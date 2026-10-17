<?php

namespace App\Services\Releases;

use App\Services\Licensing\Signer;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

/**
 * Stages a release from what is LIVE (docs/STANDALONE_EDITION_PLAN.md §10), beside WorkingCopyStager:
 * `git archive` of the deployed commit (HEAD, which deploy.sh resets to origin) of the kernel and of
 * the web app, so nothing untracked — .env, ssh notes, uploads, logs — can reach a buyer. The kernel's
 * vendor/ is tracked and comes with its archive. The web app's is copied from the deployed checkout
 * when that was installed from the same composer.lock without development packages (the normal case
 * on the SaaS host), otherwise installed fresh by Composer. Its public/build is committed.
 *
 * The stages are named budget-pro and budget-pro-new side by side, as on the server, so a fresh
 * Composer autoloader finds the kernel at ../budget-pro/app; StandaloneArchive relinks it to budget_pro.
 */
class GitArchiveStager
{
    /** @var callable(string,int):void */
    private $progress;

    public function __construct(private readonly StandaloneArchive $archive = new StandaloneArchive)
    {
    }

    public static function isCheckout(string $path): bool
    {
        return $path !== '' && is_dir($path) && (is_dir($path.'/.git') || is_file($path.'/.git'));
    }

    /** The deployed commit of a checkout: its HEAD, in full. */
    public function commit(string $repository): string
    {
        $commit = trim($this->run(['git', 'rev-parse', '--verify', 'HEAD^{commit}'], $repository));
        if (! preg_match('/^[a-f0-9]{40}$/', $commit)) {
            throw new \RuntimeException("{$repository} has no commit checked out.");
        }

        return $commit;
    }

    /** Tracked files that differ from the commit; untracked files never ship, so they do not count. @return list<string> */
    public function changes(string $repository): array
    {
        $out = $this->run(['git', '--no-optional-locks', 'status', '--porcelain', '--untracked-files=no'], $repository);

        return array_values(array_filter(array_map(static fn (string $l): string => trim(substr($l, 3)), explode("\n", $out))));
    }

    /**
     * @param  callable(string,int):void|null  $progress  a step in plain words, and a percentage of the whole build
     * @return array{path:string,sha256:string,count:int,bytes:int,manifest:array,commits:array{kernel:string,web:string},vendor:string}
     */
    public function build(string $version, string $destination, Signer $signer, ?callable $progress = null,
        ?string $kernel = null, ?string $web = null): array
    {
        $this->progress = $progress ?? static function (): void {
        };
        $repositories = ['kernel' => $kernel ?? (string) config('edition.release_sources.kernel'), 'web' => $web ?? (string) config('edition.release_sources.web')];
        $commits = [];
        foreach ($repositories as $side => $repository) {
            if (! self::isCheckout($repository)) {
                throw new \RuntimeException("The {$side} release source ({$repository}) is not a git checkout.");
            }
            if (($changed = $this->changes($repository)) !== []) {
                throw new \RuntimeException("The {$side} checkout has changes to tracked files that are not committed: "
                    .implode(', ', array_slice($changed, 0, 8)).'. A release is built from the deployed commit; deploy or reset first.');
            }
            $commits[$side] = $this->commit($repository);
        }

        $work = storage_path('app/private/release-builds/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($work, 0700);
        try {
            ($this->progress)('Exporting budget-pro at '.substr($commits['kernel'], 0, 7), 5);
            $this->export($repositories['kernel'], $commits['kernel'], $work.'/budget-pro', $work);
            ($this->progress)('Exporting budget-pro-new at '.substr($commits['web'], 0, 7), 12);
            $this->export($repositories['web'], $commits['web'], $work.'/budget-pro-new', $work);
            ($this->progress)('Preparing the web app\'s PHP dependencies', 18);
            $vendor = $this->webVendor($repositories['web'], $work.'/budget-pro-new');
            if (! is_file($work.'/budget-pro-new/public/build/manifest.json')) {
                throw new \RuntimeException('The web app commit has no built assets (public/build): run npm run build, commit, deploy, then stage again.');
            }

            ($this->progress)('Packing, signing and verifying the ZIP', 30);
            File::ensureDirectoryExists(dirname($destination), 0700);
            $built = $this->archive->build($work.'/budget-pro', $work.'/budget-pro-new', null, $destination, $version, $commits, $signer);

            return $built + ['commits' => $commits, 'vendor' => $vendor];
        } catch (\Throwable $e) {
            @unlink($destination);
            throw $e;
        } finally {
            File::deleteDirectory($work);
        }
    }

    private function export(string $repository, string $commit, string $target, string $work): void
    {
        $tar = $work.'/'.basename($target).'.tar';
        $this->run(['git', 'archive', '--format=tar', '--output='.$tar, $commit], $repository, 600);
        File::ensureDirectoryExists($target, 0700);
        $this->run(['tar', '-xf', $tar, '-C', $target], $work, 600);
        @unlink($tar);
    }

    /** @return string how the web app's vendor/ was obtained */
    private function webVendor(string $repository, string $stage): string
    {
        if (! is_file($stage.'/composer.lock')) {
            throw new \RuntimeException('The web app commit has no composer.lock.');
        }
        $installed = $repository.'/vendor/composer/installed.php';
        $meta = is_file($installed) && is_file($repository.'/vendor/autoload.php') ? (static fn (string $f) => require $f)($installed) : null;
        if (is_array($meta) && ($meta['root']['dev'] ?? true) === false && is_file($repository.'/composer.lock')
            && hash_equals((string) hash_file('sha256', $repository.'/composer.lock'), (string) hash_file('sha256', $stage.'/composer.lock'))) {
            $this->copyTree($repository.'/vendor', $stage.'/vendor');

            return 'copied from the deployed checkout';
        }

        ($this->progress)('Installing the web app\'s PHP dependencies with Composer (no development packages)', 20);
        $composer = $this->composer() ?? throw new \RuntimeException('Composer was not found on the build worker: set RELEASE_COMPOSER to its path.');
        $command = str_ends_with($composer, '.phar') || ! is_executable($composer) ? [PHP_BINARY, $composer] : [$composer];
        $this->run([...$command, 'install', '--no-dev', '--prefer-dist', '--classmap-authoritative', '--no-interaction', '--no-progress', '--no-scripts'], $stage, 1500);

        return 'installed by Composer';
    }

    private function composer(): ?string
    {
        $home = (string) (getenv('HOME') ?: '');
        foreach (array_filter([(string) config('edition.composer_binary'), $home !== '' ? $home.'/bin/composer' : '']) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        $finder = new Process(['sh', '-c', 'command -v composer']);
        $finder->run();
        $found = trim($finder->getOutput());

        return $finder->isSuccessful() && $found !== '' ? $found : null;
    }

    /** Real files only: symbolic links and nested .git folders never ship. */
    private function copyTree(string $from, string $to): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($from) + 1);
            if ($entry->isLink() || preg_match('~(^|/)\.git(/|$)~', $relative) || $entry->isDir()) {
                continue;
            }
            File::ensureDirectoryExists(dirname($to.'/'.$relative));
            copy($entry->getPathname(), $to.'/'.$relative) || throw new \RuntimeException("Could not copy vendor/{$relative}.");
        }
    }

    /** @param list<string> $arguments */
    private function run(array $arguments, string $directory, int $timeout = 120): string
    {
        // Composer is usually a `#!/usr/bin/env php` script: put this worker's PHP first on the PATH.
        $environment = ['PATH' => dirname(PHP_BINARY).PATH_SEPARATOR.(string) getenv('PATH'), 'COMPOSER_NO_INTERACTION' => '1'];
        if ((string) getenv('HOME') === '' && (string) getenv('COMPOSER_HOME') === '') {
            $environment['COMPOSER_HOME'] = storage_path('app/private/composer-home');
        }
        $process = new Process($arguments, $directory, $environment);
        $process->setTimeout($timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException('Release step failed ('.implode(' ', array_slice($arguments, 0, 2)).' in '.basename($directory).'): '.mb_substr($message, -1500));
        }

        return $process->getOutput();
    }
}

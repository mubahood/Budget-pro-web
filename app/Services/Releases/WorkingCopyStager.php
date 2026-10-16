<?php

namespace App\Services\Releases;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Stages a developer's working copies for StandaloneArchive (the `standalone:package` command):
 * every file git knows about — tracked, plus new files that are not ignored — exactly as it is on
 * disk now, plus what git does not hold: the web app's installed vendor/ and its built
 * public/build. The kernel's vendor/ is tracked, so it comes with the first part.
 *
 * Only for packages made on a developer's machine. The release builder (the next phase) stages
 * from `git archive` of the deployed commits instead, and passes its stages to the same build().
 */
final class WorkingCopyStager
{
    /** @return array{path:string,commit:string,files:int} */
    public function stage(string $repository, string $target, bool $withVendor): array
    {
        $repository = rtrim((string) realpath($repository), '/');
        if ($repository === '' || ! is_dir($repository.'/.git') && ! is_file($repository.'/.git')) {
            throw new \RuntimeException("{$repository} is not a git working copy.");
        }
        File::deleteDirectory($target);
        File::ensureDirectoryExists($target);

        $listed = array_filter(explode("\0", $this->git($repository, ['ls-files', '--cached', '--others', '--exclude-standard', '-z'])));
        $count = 0;
        foreach (array_unique($listed) as $relative) {
            $count += $this->copy($repository.'/'.$relative, $target.'/'.$relative);
        }
        $extra = $withVendor ? ['vendor', 'public/build'] : [];
        foreach ($extra as $directory) {
            if (! is_dir($repository.'/'.$directory)) {
                throw new \RuntimeException("{$repository}/{$directory} is missing: run composer install / npm run build first.");
            }
            foreach (File::allFiles($repository.'/'.$directory, true) as $file) {
                $relative = $directory.'/'.$file->getRelativePathname();
                if (! is_file($target.'/'.$relative)) {
                    $count += $this->copy($file->getPathname(), $target.'/'.$relative);
                }
            }
        }

        return ['path' => $target, 'commit' => trim($this->git($repository, ['rev-parse', 'HEAD'])), 'files' => $count];
    }

    /** 1 when copied; 0 for a deleted file, a folder (a submodule) or a symbolic link, which never ship. */
    private function copy(string $from, string $to): int
    {
        if (is_link($from) || ! is_file($from)) {
            return 0;
        }
        File::ensureDirectoryExists(dirname($to));
        if (! copy($from, $to)) {
            throw new \RuntimeException("Could not stage {$from}.");
        }

        return 1;
    }

    /** @param list<string> $arguments */
    private function git(string $repository, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $repository);
        $process->setTimeout(300);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('git '.$arguments[0].' failed in '.$repository.': '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }
}

<?php

namespace Tests\Feature\Standalone;

use App\Models\StandaloneRelease;
use App\Services\Licensing\Signer;
use App\Services\Releases\GitArchiveStager;
use App\Services\Releases\ReleaseDesk;
use App\Services\Releases\StandaloneArchive;
use App\Support\Licensing\Verifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

/**
 * Releases built from what is live (docs/STANDALONE_EDITION_PLAN.md §10): a temporary pair of git
 * repositories stands in for the deployed kernel and web app. Only committed files ship, the
 * commits are recorded in the signed PACKAGE.json, and Publish needs the proof and the current key.
 */
class ReleaseBuildTest extends TestCase
{
    use DatabaseTransactions;

    private string $root;

    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git is not installed.');
        }
        $this->root = storage_path('framework/testing/release-'.bin2hex(random_bytes(5)));
        $this->keys = json_decode((string) file_get_contents(base_path('tests/fixtures/licensing/test-keypair.json')), true);
        config(['edition.name' => 'saas', 'edition.signing_key_id' => $this->keys['key_id'], 'edition.signing_secret' => $this->keys['secret'], 'edition.public_keys' => []]);

        $this->repo('kernel', ['app/Models/Shop.php', 'bootstrap/app.php', 'config/app.php', 'database/migrations/x.php', 'lang/en.json',
            'resources/standalone/INSTALL.md', 'routes/web.php', 'vendor/autoload.php', 'artisan', 'composer.json', 'composer.lock',
            'public/css/a.css', 'public/js/a.js', 'public/vendor/laravel-admin/a.js', 'public/favicon.ico', 'tests/ShouldNotShip.php', 'docs/notes.md'],
            ['.env' => 'DB_PASSWORD=never', 'storage/logs/laravel.log' => 'log', 'app/Untracked.php' => '<?php // not committed']);
        $this->repo('web', ['app/Shop/Thing.php', 'bootstrap/app.php', 'compat/x.php', 'config/app.php', 'lang/en.json', 'resources/views/a.blade.php',
            'routes/web.php', 'artisan', 'composer.lock', 'public/build/manifest.json', 'public/build/assets/a.js', 'public/vendor/livewire/livewire.min.js',
            'public/images/logo.png', 'public/robots.txt', 'deploy/standalone-index.php', 'deploy/standalone-htaccess', 'deploy/install.php', 'tests/x.php'],
            ['.env' => 'APP_KEY=never', 'vendor/autoload.php' => '<?php // deployed', 'vendor/composer/installed.php' => "<?php return ['root' => ['dev' => false]];",
                'vendor/composer/autoload_psr4.php' => "<?php return ['App\\\\' => array(\$baseDir . '/app', \$baseDir . '/../budget-pro/app')];"],
            ['composer.json' => '{"autoload":{"psr-4":{"App\\\\":["app/","../budget-pro/app/"]}}}']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** @param list<string> $tracked @param array<string,string> $untracked @param array<string,string> $trackedContents */
    private function repo(string $side, array $tracked, array $untracked, array $trackedContents = []): void
    {
        $dir = $this->root.'/'.$side;
        foreach (array_fill_keys($tracked, null) + $trackedContents as $file => $contents) {
            File::ensureDirectoryExists(dirname($dir.'/'.$file));
            file_put_contents($dir.'/'.$file, $contents ?? "{$side}:{$file}");
        }
        foreach ([['init', '-q'], ['add', '-A'], ['-c', 'user.email=release@test', '-c', 'user.name=Release Test', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'Deployed']] as $args) {
            (new Process(['git', ...$args], $dir))->mustRun();
        }
        foreach ($untracked as $file => $contents) {
            File::ensureDirectoryExists(dirname($dir.'/'.$file));
            file_put_contents($dir.'/'.$file, $contents);
        }
    }

    private function headCommit(string $side): string
    {
        return trim((new Process(['git', 'rev-parse', 'HEAD'], $this->root.'/'.$side))->mustRun()->getOutput());
    }

    private function build(string $zip, array &$steps = []): array
    {
        return (new GitArchiveStager)->build('1.4.0', $zip, new Signer, function (string $step, int $percent) use (&$steps): void {
            $steps[] = $percent;
        }, $this->root.'/kernel', $this->root.'/web');
    }

    public function test_only_the_deployed_commits_ship_and_package_json_records_both(): void
    {
        $zip = $this->root.'/out/release.zip';
        $steps = [];
        $built = $this->build($zip, $steps);

        $commits = ['kernel' => $this->headCommit('kernel'), 'web' => $this->headCommit('web')];
        $this->assertSame($commits, $built['commits']);
        $this->assertSame('copied from the deployed checkout', $built['vendor']);
        $this->assertNotEmpty($steps);
        $manifest = (new StandaloneArchive)->verify($zip, new Verifier([$this->keys['key_id'] => $this->keys['public']]));
        $this->assertSame($commits, $manifest['commits']);
        $this->assertSame('1.4.0', $manifest['version']);

        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::RDONLY);
        $names = array_map(fn ($i) => (string) $archive->getNameIndex($i), range(0, $archive->numFiles - 1));
        $release = json_decode((string) $archive->getFromName('budget_pro/RELEASE.json'), true);
        $archive->close();
        $this->assertSame($commits, $release['commits']);
        foreach (['budget_pro/app/Models/Shop.php', 'budget_pro/vendor/autoload.php', 'budget_pro_web/vendor/autoload.php', 'web_root_files/index.php', 'INSTALL.md'] as $expected) {
            $this->assertContains($expected, $names);
        }
        $this->assertNotContains('budget_pro/app/Untracked.php', $names, 'an uncommitted file never ships');
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('~(^|/)\.env|(^|/)(tests|docs|deploy|storage/logs)/.~', $name);
        }

        // Publish: only once proven, only with the archive intact, and only under the CURRENT signing key.
        $release = StandaloneRelease::create(['version' => '1.4.0', 'status' => StandaloneRelease::STAGED, 'archive_path' => $zip, 'sha256' => $built['sha256'],
            'commit_kernel' => $commits['kernel'], 'commit_web' => $commits['web'], 'staged_at' => now()]);
        $desk = app(ReleaseDesk::class);
        $this->assertRefused(fn () => $desk->publish($release), 'clean install');
        $release->forceFill(['proved_at' => now()])->save();

        $pair = sodium_crypto_sign_keypair();
        config(['edition.signing_key_id' => 'rotated', 'edition.signing_secret' => base64_encode(sodium_crypto_sign_secretkey($pair))]);
        $this->assertRefused(fn () => app(ReleaseDesk::class)->publish($release), 'signature');
        config(['edition.signing_key_id' => $this->keys['key_id'], 'edition.signing_secret' => $this->keys['secret']]);

        $desk = app(ReleaseDesk::class);
        $desk->publish($release);
        $this->assertSame([StandaloneRelease::PUBLISHED, [$release->id]], [$release->fresh()->status, StandaloneRelease::available()->pluck('id')->all()]);

        $desk->withdraw($release);
        $this->assertSame(StandaloneRelease::YANKED, $release->fresh()->status);
        $this->assertSame([], StandaloneRelease::available()->pluck('id')->all(), 'a withdrawn release is offered to no one');
        $this->assertRefused(fn () => $desk->publish($release->fresh()), 'clean install');
    }

    public function test_a_changed_tracked_file_stops_the_build(): void
    {
        file_put_contents($this->root.'/kernel/routes/web.php', '<?php // edited on the server');
        $this->assertRefused(fn () => $this->build($this->root.'/release.zip'), 'routes/web.php');
        $this->assertFileDoesNotExist($this->root.'/release.zip');
    }

    public function test_composer_installs_the_web_vendor_when_the_deployed_one_has_development_packages(): void
    {
        file_put_contents($this->root.'/web/vendor/composer/installed.php', "<?php return ['root' => ['dev' => true]];");
        $log = $this->root.'/composer.json.log';
        $fake = str_replace('LOG', var_export($log, true), <<<'PHP'
            <?php
            file_put_contents(LOG, json_encode(['args' => array_slice($argv, 1), 'kernel_beside' => is_dir(getcwd().'/../budget-pro/app')]));
            @mkdir('vendor/composer', 0777, true);
            file_put_contents('vendor/autoload.php', '<?php // installed');
            file_put_contents('vendor/composer/autoload_psr4.php', file_get_contents(__DIR__.'/web/vendor/composer/autoload_psr4.php'));
            PHP);
        file_put_contents($this->root.'/fake-composer.php', $fake);
        config(['edition.composer_binary' => $this->root.'/fake-composer.php']);

        $built = $this->build($this->root.'/release.zip');

        $this->assertSame('installed by Composer', $built['vendor']);
        $ran = json_decode((string) file_get_contents($log), true);
        $this->assertSame(['install', '--no-dev', '--prefer-dist', '--classmap-authoritative'], array_slice($ran['args'], 0, 4));
        $this->assertTrue($ran['kernel_beside'], 'the staged kernel sits at ../budget-pro, as on the server');
    }

    private function assertRefused(callable $action, string $because): void
    {
        try {
            $action();
            $this->fail("Expected a refusal mentioning \"{$because}\".");
        } catch (\DomainException|\RuntimeException $e) {
            $this->assertStringContainsString($because, $e->getMessage());
        }
    }
}

<?php

namespace Tests\Feature\Standalone;

use App\Services\Install\ArtisanRunner;
use App\Services\Install\DatabaseBackup;
use App\Services\Install\InstallationBackups;
use App\Services\Install\StandaloneUpdater;
use App\Services\Install\SystemState;
use App\Services\Install\UpdateChannel;
use App\Services\Licensing\Signer;
use App\Services\Releases\StandaloneArchive;
use App\Support\Licensing\Canonical;
use App\Support\Licensing\InstalledLicence;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * Updating an installed standalone copy (docs/STANDALONE_EDITION_PLAN.md §12.2, §18 "Updater"): only a
 * genuine, newer, entitled package is accepted; a good one replaces the code of both apps and the web
 * folder by its manifest and migrates on the new code; a failure puts the code and database back.
 * Every write goes to temporary folders and a fake runner, never into the application running the tests.
 */
class StandaloneUpdateTest extends TestCase
{
    private string $root;

    private string $kernel;

    private string $web;

    private string $docroot;

    private string $secret;

    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/update-'.bin2hex(random_bytes(5)));
        [$this->kernel, $this->web, $this->docroot] = [$this->root.'/budget_pro', $this->root.'/budget_pro_web', $this->root.'/public_html'];
        foreach ([$this->kernel.'/storage/app/private', $this->web.'/storage', $this->docroot.'/storage'] as $dir) {
            File::ensureDirectoryExists($dir);
        }
        file_put_contents($this->web.'/artisan', 'fixture');
        file_put_contents($this->kernel.'/.env', "APP_KEY=base64:kernel\n");
        file_put_contents($this->web.'/.env', "APP_KEY=base64:web\n");
        file_put_contents($this->docroot.'/storage/photo.jpg', 'a product photo');

        $pair = sodium_crypto_sign_keypair();
        $this->secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
        config(['edition.installed_path' => $this->kernel.'/storage/app/private/installed.json', 'edition.update_server' => 'http://localhost']);

        // The installed 1.0.0: a kernel file the update changes, one it drops, a web app screen and the front controller.
        $this->installed('1.0.0', ['budget_pro/app/Marker.php' => 'old', 'budget_pro/app/Gone.php' => 'gone',
            'budget_pro_web/app/Screen.php' => 'old screen', 'web_root_files/index.php' => 'old index']);
        $this->licence('2099-12-31');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        InstalledLicence::forget();
        parent::tearDown();
    }

    private const NEW_FILES = ['budget_pro/app/Marker.php' => 'new', 'budget_pro/app/Added.php' => 'added',
        'budget_pro_web/app/Screen.php' => 'new screen', 'web_root_files/index.php' => 'new index'];

    public function test_a_genuine_newer_package_replaces_both_apps_and_the_web_folder_and_migrates_on_the_new_code(): void
    {
        $zip = $this->package('1.1.0', self::NEW_FILES);
        [$runner, $backup] = [new UpdateFakeRunner, new UpdateFakeBackup];

        $result = $this->updater($backup, $runner)->apply($zip);

        $this->assertSame(['1.0.0', '1.1.0'], [$result['from'], $result['to']]);
        $this->assertSame('new', file_get_contents($this->kernel.'/app/Marker.php'));
        $this->assertSame('added', file_get_contents($this->kernel.'/app/Added.php'));
        $this->assertFileDoesNotExist($this->kernel.'/app/Gone.php');
        $this->assertSame('new screen', file_get_contents($this->web.'/app/Screen.php'));
        $this->assertSame('new index', file_get_contents($this->docroot.'/index.php'));
        // Never the buyer's settings or photos.
        $this->assertSame("APP_KEY=base64:kernel\n", file_get_contents($this->kernel.'/.env'));
        $this->assertSame("APP_KEY=base64:web\n", file_get_contents($this->web.'/.env'));
        $this->assertSame('a product photo', file_get_contents($this->docroot.'/storage/photo.jpg'));
        $this->assertSame('1.1.0', $this->readJson($this->root.'/PACKAGE.json')['manifest']['version']);
        $this->assertSame('1.1.0', $this->readJson($this->kernel.'/RELEASE.json')['version']);

        $this->assertSame([
            ['budget_pro', 'down'], ['budget_pro_web', 'down'],
            ['budget_pro', 'migrate', '--force'], ['budget_pro', 'db:seed', '--class=Database\\Seeders\\StandaloneReferenceSeeder', '--force'],
            ['budget_pro', 'licence:apply'], ['budget_pro', 'cache:kernel'], ['budget_pro_web', 'cache:web'],
            ['budget_pro', 'standalone:health'], ['budget_pro', 'up'], ['budget_pro_web', 'up'],
        ], $runner->calls);
        $this->assertTrue($backup->dumped);
        $this->assertFalse($backup->restored);
        $this->assertSame('old', file_get_contents($result['backup'].'/code/budget_pro/app/Marker.php'));
        $this->assertFileExists($result['backup'].'/web.env');
        $this->assertSame('1.1.0', $this->readJson(config('edition.installed_path'))['version']);
        $this->assertSame('succeeded', (new SystemState)->current()['status']);
    }

    public function test_a_failed_migration_puts_the_code_and_database_back_and_reports_failed(): void
    {
        $zip = $this->package('1.1.0', self::NEW_FILES);
        [$runner, $backup] = [new UpdateFakeRunner(failOn: 'migrate'), new UpdateFakeBackup];

        try {
            $this->updater($backup, $runner)->apply($zip);
            $this->fail('The update should have stopped.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fixture failure', $e->getMessage());
        }

        $this->assertSame('old', file_get_contents($this->kernel.'/app/Marker.php'));
        $this->assertSame('gone', file_get_contents($this->kernel.'/app/Gone.php'));
        $this->assertFileDoesNotExist($this->kernel.'/app/Added.php');
        $this->assertSame('old screen', file_get_contents($this->web.'/app/Screen.php'));
        $this->assertSame('old index', file_get_contents($this->docroot.'/index.php'));
        $this->assertSame('1.0.0', $this->readJson($this->root.'/PACKAGE.json')['manifest']['version']);
        $this->assertSame('1.0.0', $this->readJson($this->kernel.'/RELEASE.json')['version']);
        $this->assertSame('1.0.0', $this->readJson(config('edition.installed_path'))['version']);
        $this->assertTrue($backup->restored);
        $this->assertContains(['budget_pro', 'up'], $runner->calls);
        $this->assertSame(['budget_pro_web', 'up'], end($runner->calls));
        $state = (new SystemState)->current();
        $this->assertSame('failed', $state['status']);
        $this->assertStringContainsString('Putting the previous release back', implode("\n", $state['log']));
    }

    public function test_a_tampered_package_is_refused_before_anything_changes(): void
    {
        $zip = $this->package('1.1.0', self::NEW_FILES);
        $archive = new ZipArchive;
        $archive->open($zip);
        $archive->addFromString('budget_pro/app/Marker.php', 'evil');
        $archive->close();

        try {
            $this->updater(new UpdateFakeBackup, new UpdateFakeRunner)->inspect($zip);
            $this->fail('A tampered package must be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('not a genuine Budget Pro update', $e->getMessage());
        }
        $this->assertSame('old', file_get_contents($this->kernel.'/app/Marker.php'));
    }

    public function test_a_package_signed_by_a_key_this_installation_does_not_trust_is_refused(): void
    {
        $zip = $this->package('1.1.0', self::NEW_FILES);
        config(['edition.public_keys' => ['fixture' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]]);

        $this->expectExceptionMessage('not a genuine Budget Pro update');
        $this->updater(new UpdateFakeBackup, new UpdateFakeRunner)->inspect($zip);
    }

    public function test_a_release_made_after_the_updates_ended_is_refused(): void
    {
        $this->licence('2020-01-01');
        $zip = $this->package('1.1.0', self::NEW_FILES);

        $this->expectExceptionMessage('after your updates ended on 01 Jan 2020');
        $this->updater(new UpdateFakeBackup, new UpdateFakeRunner)->inspect($zip);
    }

    public function test_a_release_that_is_not_newer_is_refused(): void
    {
        $zip = $this->package('1.0.0', self::NEW_FILES);

        $this->expectExceptionMessage('already runs 1.0.0');
        $this->updater(new UpdateFakeBackup, new UpdateFakeRunner)->inspect($zip);
    }

    // ── The request the page leaves, and the scheduler that runs it ──────────

    public function test_one_request_at_a_time_and_it_is_claimed_exactly_once(): void
    {
        $state = new SystemState;
        $state->request(['action' => 'backup', 'by' => 1]);
        $this->assertTrue($state->busy());
        try {
            $state->request(['action' => 'update', 'source' => 'online']);
            $this->fail('A second request must wait.');
        } catch (\DomainException) {
        }

        $this->assertSame('backup', $state->claim()['action']);
        $this->assertNull($state->claim());
        $this->assertSame('queued', $state->current()['status']);
        $this->assertSame(dirname(config('edition.installed_path')).'/system', $state->directory(), 'both apps find it beside the install marker');
    }

    public function test_the_scheduler_makes_the_backup_the_page_asked_for(): void
    {
        config(['edition.name' => 'standalone']);
        (new SystemState)->request(['action' => 'backup', 'by' => 7]);
        $this->app->instance(InstallationBackups::class, new InstallationBackups(new UpdateFakeBackup, $this->web.'/.env', $this->docroot.'/storage',
            SystemState::backupsDirectory(), $this->kernel.'/.env'));

        $this->artisan('standalone:system-requests')->assertSuccessful();

        $state = (new SystemState)->current();
        $this->assertSame(['succeeded', 'backup'], [$state['status'], $state['action']]);
        $this->assertStringContainsString('Backup ready', $state['message']);
        $this->assertCount(1, app(InstallationBackups::class)->list());
        $this->assertNull((new SystemState)->pending());
    }

    public function test_health_runs_the_web_apps_shop_check_and_says_what_is_wrong(): void
    {
        config(['edition.name' => 'standalone']);
        $this->app->instance(ArtisanRunner::class, new UpdateFakeRunner(failOn: 'shop:check'));

        $this->artisan('standalone:health', ['--web' => $this->web])
            ->expectsOutputToContain('shop:check failed')
            ->expectsOutputToContain('budget_pro_web/bootstrap/cache is missing or not writable')
            ->assertFailed();
    }

    // ── The update server ────────────────────────────────────────────────────

    public function test_the_channel_sends_only_the_licence_and_version_and_downloads_only_from_the_update_server(): void
    {
        $body = 'a release archive';
        $sha = hash('sha256', $body);
        Http::fake([
            'localhost/standalone/updates*' => Http::response(['latest' => ['version' => '9.9.9', 'notes' => 'Fixes', 'published_at' => '2026-10-01',
                'size' => strlen($body), 'sha256' => $sha, 'download_url' => 'http://localhost/standalone/download/9.9.9'], 'updates_until' => '2099-12-31']),
            'localhost/standalone/download/*' => Http::response($body),
        ]);
        $channel = new UpdateChannel;

        $latest = $channel->check();
        $this->assertSame('9.9.9', $latest['version']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/standalone/updates?') && $r['licence'] === 'lic-test' && isset($r['version']) && count($r->data()) === 2);
        $this->assertSame('9.9.9', $channel->lastCheck()['latest']['version']);
        $this->assertSame('2099-12-31', $channel->lastCheck()['updates_until']);

        $file = $channel->download($latest['download_url'], $sha);
        $this->assertSame($body, file_get_contents($file));
        $this->assertStringStartsWith(SystemState::updatesDirectory().'/incoming-', $file);

        foreach ([['http://localhost/standalone/download/9.9.9', str_repeat('0', 64)], ['https://evil.example/x.zip', $sha]] as [$url, $hash]) {
            try {
                $channel->download($url, $hash);
                $this->fail("{$url} must be refused.");
            } catch (\DomainException) {
            }
        }
        $this->assertSame([$file], glob(SystemState::updatesDirectory().'/incoming-*'), 'a download that fails its checksum is deleted');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function updater(UpdateFakeBackup $backup, UpdateFakeRunner $runner): StandaloneUpdater
    {
        config(['edition.name' => 'standalone']);

        return new StandaloneUpdater($backup, new SystemState, $runner, $this->kernel, $this->web, $this->docroot);
    }

    /** A signed package of both apps and the web folder, with these files (package path => contents) over the minimum. */
    private function package(string $version, array $files): string
    {
        config(['edition.name' => 'saas', 'edition.signing_key_id' => 'fixture', 'edition.signing_secret' => $this->secret]);
        $stage = $this->root.'/stage-'.$version.'-'.bin2hex(random_bytes(3));
        $sides = ['budget_pro' => $stage.'/kernel', 'budget_pro_web' => $stage.'/web', 'web_root_files' => $stage.'/docroot'];
        $minimum = [
            'budget_pro' => ['bootstrap/app.php', 'config/app.php', 'resources/standalone/INSTALL.md', 'routes/web.php', 'vendor/autoload.php', 'artisan', 'composer.json', 'composer.lock'],
            'budget_pro_web' => ['bootstrap/app.php', 'config/app.php', 'resources/views/a.blade.php', 'routes/web.php', 'vendor/autoload.php', 'artisan', 'composer.lock'],
            'web_root_files' => ['index.php', '.htaccess', 'install.php', 'build/manifest.json'],
        ];
        foreach ($minimum as $side => $list) {
            foreach ($list as $file) {
                $this->write($sides[$side].'/'.$file, "{$side}:{$file}");
            }
        }
        $this->write($stage.'/web/public/build/manifest.json', '{}');
        $this->write($stage.'/web/vendor/composer/autoload_psr4.php', "<?php return ['App\\\\' => array(\$baseDir . '/app', \$baseDir . '/../budget-pro/app')];");
        $this->write($stage.'/web/composer.json', '{"autoload":{"psr-4":{"App\\\\":["app/","../budget-pro/app/"]}}}');
        foreach ($files as $path => $contents) {
            [$side, $inside] = explode('/', $path, 2);
            $this->write($sides[$side].'/'.$inside, $contents);
        }

        $zip = $this->root.'/budget-pro-standalone-'.$version.'-'.bin2hex(random_bytes(3)).'.zip';
        (new StandaloneArchive)->build($sides['budget_pro'], $sides['budget_pro_web'], $sides['web_root_files'], $zip, $version,
            ['kernel' => str_repeat('a', 40), 'web' => str_repeat('b', 40)], new Signer, []);
        config(['edition.name' => 'standalone', 'edition.signing_key_id' => null, 'edition.signing_secret' => null, 'edition.public_keys' => ['fixture' => $this->public]]);

        return $zip;
    }

    /** Lay out an installed release: its files, RELEASE.json, the PACKAGE.json beside the apps, installed.json. */
    private function installed(string $version, array $files): void
    {
        $files['budget_pro/RELEASE.json'] = json_encode(['version' => $version]);
        $roots = ['budget_pro' => $this->kernel, 'budget_pro_web' => $this->web, 'web_root_files' => $this->docroot];
        $manifest = ['format' => 1, 'product' => StandaloneArchive::PRODUCT, 'version' => $version, 'files' => []];
        foreach ($files as $path => $contents) {
            [$side, $inside] = explode('/', $path, 2);
            $this->write($roots[$side].'/'.$inside, $contents);
            $manifest['files'][$path] = ['sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
        }
        file_put_contents($this->root.'/PACKAGE.json', json_encode(['manifest' => $manifest]));
        file_put_contents(config('edition.installed_path'), json_encode(['edition' => 'standalone', 'version' => $version]));
    }

    private function licence(string $updatesUntil): void
    {
        $payload = ['format' => 1, 'product' => InstalledLicence::PRODUCT, 'id' => 'lic-test', 'customer' => 'Fresh Mart', 'domains' => ['localhost'],
            'updates_until' => $updatesUntil, 'limits' => ['max_users' => null, 'max_locations' => null, 'max_devices' => null], 'white_label' => false];
        $signature = base64_encode(sodium_crypto_sign_detached(Canonical::json($payload), (string) base64_decode($this->secret)));
        $path = $this->kernel.'/storage/app/private/licence.json';
        file_put_contents($path, json_encode(['licence' => $payload, 'key_id' => 'fixture', 'signature' => $signature]));
        config(['edition.licence_path' => $path, 'edition.public_keys' => ['fixture' => $this->public]]);
        InstalledLicence::forget();
    }

    private function write(string $file, string $contents): void
    {
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, $contents);
    }

    private function readJson(string $file): array
    {
        return json_decode((string) file_get_contents($file), true);
    }
}

/** Records what the updater asked artisan to do in which app, and never does it. */
class UpdateFakeRunner extends ArtisanRunner
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function __construct(private readonly ?string $failOn = null) {}

    public function available(): bool
    {
        return true;
    }

    public function down(string $appRoot): void
    {
        $this->calls[] = [basename($appRoot), 'down'];
    }

    public function up(string $appRoot): void
    {
        $this->calls[] = [basename($appRoot), 'up'];
    }

    public function cache(string $appRoot, string $side): void
    {
        $this->calls[] = [basename($appRoot), 'cache:'.$side];
    }

    public function fresh(string $appRoot, array $arguments): void
    {
        $this->calls[] = [basename($appRoot), ...$arguments];
        if ($this->failOn !== null && $arguments[0] === $this->failOn) {
            throw new \RuntimeException("`{$this->failOn}` failed: fixture failure");
        }
    }
}

/** A database backup that only remembers being asked. */
class UpdateFakeBackup extends DatabaseBackup
{
    public bool $dumped = false;

    public bool $restored = false;

    public function dump(string $path): string
    {
        $this->dumped = true;
        $path = str_ends_with($path, '.sql.gz') ? $path : $path.'.sql.gz';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'fixture');

        return $path;
    }

    public function restore(string $path): void
    {
        $this->restored = true;
    }
}

<?php

namespace Tests\Feature\Standalone;

use App\Services\Licensing\Signer;
use App\Services\Releases\PackageLayout;
use App\Services\Releases\StandaloneArchive;
use App\Support\Licensing\Verifier;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * The signed standalone ZIP (docs/STANDALONE_EDITION_PLAN.md §8.1, §18 "Archive"), built from two
 * small fixture stages and signed with the throwaway key in tests/fixtures/licensing.
 */
class StandaloneArchiveTest extends TestCase
{
    private string $root;

    private string $zip;

    private array $keys;

    private const COMMITS = ['kernel' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'web' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/archive-'.bin2hex(random_bytes(5)));
        $this->zip = $this->root.'/release.zip';
        $files = [
            'kernel' => ['app/Models/Shop.php', 'bootstrap/app.php', 'config/app.php', 'database/migrations/x.php', 'lang/en.json',
                'resources/standalone/INSTALL.md', 'routes/web.php', 'vendor/autoload.php', 'vendor/laravel/sail/database/pgsql/x.sql',
                'artisan', 'composer.json', 'composer.lock', 'public/css/a.css', 'public/js/a.js', 'public/vendor/laravel-admin/a.js',
                'public/favicon.ico', 'app/.DS_Store', 'tests/ShouldNotShip.php', 'docs/notes.md'],
            'web' => ['app/Shop/Thing.php', 'bootstrap/app.php', 'compat/x.php', 'config/app.php', 'database/.gitignore', 'lang/en.json',
                'resources/views/a.blade.php', 'routes/web.php', 'vendor/autoload.php', 'artisan', 'composer.lock',
                'public/build/manifest.json', 'public/build/assets/a.js', 'public/vendor/livewire/livewire.min.js', 'public/images/logo.png',
                'public/robots.txt', 'deploy/standalone-index.php', 'deploy/standalone-htaccess', 'deploy/install.php', 'node_modules/x.js'],
        ];
        foreach ($files as $side => $list) {
            foreach ($list as $file) {
                File::ensureDirectoryExists(dirname($this->root.'/'.$side.'/'.$file));
                file_put_contents($this->root.'/'.$side.'/'.$file, "{$side}:{$file}");
            }
        }
        File::ensureDirectoryExists($this->root.'/web/vendor/composer');
        file_put_contents($this->root.'/web/vendor/composer/autoload_psr4.php', "<?php return ['App\\\\' => array(\$baseDir . '/app', \$baseDir . '/../budget-pro/app')];");
        file_put_contents($this->root.'/web/composer.json', '{"autoload":{"psr-4":{"App\\\\":["app/","../budget-pro/app/"]}}}');

        $this->keys = json_decode((string) file_get_contents(base_path('tests/fixtures/licensing/test-keypair.json')), true);
        config(['edition.name' => 'saas', 'edition.signing_key_id' => $this->keys['key_id'], 'edition.signing_secret' => $this->keys['secret'], 'edition.public_keys' => []]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function build(?string $webRoot = null): array
    {
        return (new StandaloneArchive)->build($this->root.'/kernel', $this->root.'/web', $webRoot, $this->zip, '1.2.3', self::COMMITS, new Signer);
    }

    private function verifier(): Verifier
    {
        return new Verifier([$this->keys['key_id'] => $this->keys['public']]);
    }

    public function test_the_package_is_signed_laid_out_for_shared_hosting_and_verifies_in_place(): void
    {
        $built = $this->build();
        $this->assertSame(hash_file('sha256', $this->zip), $built['sha256']);
        $this->assertSame('1.2.3', (new StandaloneArchive)->verify($this->zip, $this->verifier())['version']);

        $zip = new ZipArchive;
        $zip->open($this->zip, ZipArchive::RDONLY);
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $release = json_decode((string) $zip->getFromName('budget_pro/RELEASE.json'), true);
        $trusted = json_decode((string) $zip->getFromName('budget_pro/RELEASE_KEYS.json'), true);
        $autoload = (string) $zip->getFromName('budget_pro_web/vendor/composer/autoload_psr4.php');
        $zip->close();

        foreach (['PACKAGE.json', 'INSTALL.md', 'budget_pro/artisan', 'budget_pro/vendor/autoload.php', 'budget_pro_web/compat/x.php',
            'web_root_files/index.php', 'web_root_files/.htaccess', 'web_root_files/install.php', 'web_root_files/build/manifest.json',
            'web_root_files/vendor/livewire/livewire.min.js', 'web_root_files/css/a.css', 'web_root_files/vendor/laravel-admin/a.js',
            'web_root_files/storage/', 'budget_pro/storage/app/private/', 'budget_pro_web/bootstrap/cache/'] as $expected) {
            $this->assertContains($expected, $names);
        }
        foreach (array_filter($names, fn ($n) => ! str_ends_with($n, '/')) as $name) {
            $this->assertNull(PackageLayout::secretReason($name), $name);
            $this->assertDoesNotMatchRegularExpression('~(^|/)(tests|docs|node_modules|deploy)/|sail/database|\.DS_Store|\.gitignore~', $name);
        }
        $this->assertSame(['commits' => self::COMMITS, 'edition' => 'standalone', 'mobile_min_version' => (string) config('mobile.min_version'), 'version' => '1.2.3'],
            array_diff_key($release, ['built_at' => 1]));
        $this->assertSame([$this->keys['key_id'] => $this->keys['public']], $trusted);
        $this->assertStringContainsString("'/../budget_pro/app'", $autoload, 'the web app loads the kernel from the packaged folder name');

        // As on cPanel: the apps in the home folder, web_root_files moved into a nested web folder.
        $home = $this->root.'/home';
        $zip->open($this->zip, ZipArchive::RDONLY);
        $zip->extractTo($home);
        $zip->close();
        File::ensureDirectoryExists($home.'/public_html');
        rename($home.'/web_root_files', $home.'/public_html/shop');
        $this->assertSame('1.2.3', (new StandaloneArchive)->verifyExtracted($home, $home.'/public_html/shop', $this->verifier())['version']);

        file_put_contents($home.'/budget_pro/app/Models/Shop.php', '<?php // changed');
        $this->expectException(\RuntimeException::class);
        (new StandaloneArchive)->verifyExtracted($home, $home.'/public_html/shop', $this->verifier());
    }

    /** Files 0644 (an in-memory entry otherwise extracts 0666), and only the folders PHP writes 0777; one fixed mtime. */
    public function test_modes_and_times_are_fixed(): void
    {
        $this->build();
        $zip = new ZipArchive;
        $zip->open($this->zip, ZipArchive::RDONLY);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            $name = (string) $zip->getNameIndex($i);
            $this->assertSame(ZipArchive::OPSYS_UNIX, $system, $name);
            $this->assertSame(str_ends_with($name, '/') ? 0777 : 0644, ($attributes >> 16) & 0777, $name);
            $this->assertSame(315532800, $zip->statIndex($i)['mtime'], $name);
        }
        $zip->close();
    }

    public function test_a_planted_secret_stops_the_build(): void
    {
        $planted = ['kernel/app/.env', 'kernel/config/server.pem', 'web/routes/live.sql.gz', 'kernel/resources/LIVE_ACCOUNTS.local.md',
            'web/bootstrap/cache/config.php', 'kernel/vendor/x/id_rsa', 'web/app/ssh_access.txt', 'kernel/database/backup.sqlite'];
        foreach ($planted as $file) {
            File::ensureDirectoryExists(dirname($this->root.'/'.$file));
            file_put_contents($this->root.'/'.$file, 'DB_PASSWORD=must-never-ship');
            try {
                $this->build();
                $this->fail("{$file} was packaged");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Refusing to package', $e->getMessage(), $file);
            } finally {
                @unlink($this->root.'/'.$file);
            }
        }
        // A web folder handed over whole may not carry uploaded files either.
        File::ensureDirectoryExists($this->root.'/webroot/storage');
        file_put_contents($this->root.'/webroot/storage/photo.jpg', 'x');
        $this->expectExceptionMessage('storage contents');
        $this->build($this->root.'/webroot');
    }

    public function test_a_tampered_or_foreign_signed_archive_is_refused(): void
    {
        $this->build();
        $zip = new ZipArchive;
        $zip->open($this->zip);
        $zip->addFromString('web_root_files/install.php', 'WEB:DEPLOY/INSTALL.PHP'); // the same size, other bytes
        $zip->close();
        try {
            (new StandaloneArchive)->verify($this->zip, $this->verifier());
            $this->fail('a tampered file verified');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('hash does not match', $e->getMessage());
        }

        $this->build();
        $other = sodium_crypto_sign_keypair();
        $this->expectExceptionMessage('signature is invalid');
        (new StandaloneArchive)->verify($this->zip, new Verifier([$this->keys['key_id'] => base64_encode(sodium_crypto_sign_publickey($other))]));
    }
}

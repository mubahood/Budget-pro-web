<?php

namespace Tests\Feature\Standalone;

use App\Services\Install\EnvironmentWriter;
use App\Services\Install\InstallCaches;
use App\Services\Install\SingleBusinessGuard;
use App\Services\Install\StandaloneEnvironment;
use App\Services\Install\StandaloneInstaller;
use App\Services\Licensing\Signer;
use App\Support\Licensing\InstalledLicence;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The installer engine (docs/STANDALONE_EDITION_PLAN.md §9, §18 "Installer") on a throwaway MySQL
 * database: short steps install every migration, then one business, a verified owner on the
 * Standalone plan, the one-business triggers, the licence's limits and installed.json. And the two
 * .env files the installer writes: one APP_KEY, every value round-trips.
 */
class StandaloneInstallerTest extends TestCase
{
    private const DETAILS = [
        'business_name' => 'Mama Amina\'s "Duka" #1', 'business_type' => 'supermarket', 'country' => 'KE',
        'owner_name' => 'Amina Wanjiru Otieno', 'owner_email' => 'Owner@Install.test', 'owner_phone' => '+254 712 345678',
        'owner_password' => 'install-test-password',
    ];

    public function test_short_steps_install_every_migration_then_one_verified_business_on_the_standalone_plan(): void
    {
        $keys = json_decode((string) file_get_contents(base_path('tests/fixtures/licensing/test-keypair.json')), true);
        $work = storage_path('framework/testing/install-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($work);
        config(['edition.name' => 'saas', 'edition.signing_key_id' => $keys['key_id'], 'edition.signing_secret' => $keys['secret']]);
        $document = app(Signer::class)->issue(['customer' => 'Install Test', 'domains' => ['localhost'], 'updates_until' => '2099-12-31',
            'limits' => ['max_users' => 5], 'id' => 'install-test-licence']);
        file_put_contents($work.'/licence.json', json_encode($document));

        $database = 'bp_install_'.bin2hex(random_bytes(4));
        $original = config('database.connections.mysql.database');
        DB::statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->app->instance(InstallCaches::class, new class extends InstallCaches
        {
            public function warm(string $kernelRoot, ?string $webRoot): array
            {
                return []; // never write into this working copy's bootstrap/cache
            }
        });
        try {
            config([
                'edition.name' => 'standalone', 'edition.signing_key_id' => null, 'edition.signing_secret' => null,
                'edition.public_keys' => [$keys['key_id'] => $keys['public']],
                'edition.licence_path' => $work.'/licence.json', 'edition.installed_path' => $work.'/installed.json',
                'app.url' => 'http://localhost', 'database.connections.mysql.database' => $database,
            ]);
            DB::purge('mysql');
            InstalledLicence::forget();
            $installer = app(StandaloneInstaller::class);

            $first = $installer->advance(self::DETAILS, 5);
            $this->assertFalse($first['done']);
            $this->assertSame(5, $first['completed']);
            $steps = 1;
            do {
                $state = $installer->advance(self::DETAILS, 5);
                $steps++;
            } while (! $state['done'] && $steps < 80);

            $this->assertTrue($state['done']);
            $this->assertGreaterThan(20, $steps, 'a few migrations per request');
            $this->assertSame(count(glob(base_path('database/migrations/*.php'))), DB::table('migrations')->count());
            $company = DB::table('companies')->sole();
            $this->assertSame([self::DETAILS['business_name'], 'KES', 'Africa/Nairobi', 'KE', 'supermarket', 1],
                [$company->name, $company->currency, $company->timezone, $company->country, $company->business_type, (int) $company->standalone_slot]);
            $this->assertEquals(16, $company->tax_rate, 'the country\'s VAT');
            $owner = DB::table('admin_users')->where('id', $company->owner_id)->first();
            $this->assertSame('owner@install.test', $owner->email);
            $this->assertSame((int) $company->id, (int) $owner->company_id);
            $this->assertNotNull($owner->email_verified_at, 'the owner is not asked to verify the address they installed with');
            $this->assertSame(1, DB::table('admin_users')->count());

            $plan = DB::table('plans')->where('slug', 'standalone')->sole();
            // No SaaS plans are seeded; the "free" row is inserted by a 2026_09_27 migration (billing's downgrade target, unused here).
            $this->assertSame(['free', 'standalone'], DB::table('plans')->orderBy('slug')->pluck('slug')->all());
            $this->assertTrue(DB::table('subscriptions')->where('company_id', $company->id)->where('plan_id', $plan->id)->where('status', 'active')->exists());
            $this->assertSame(5, json_decode($plan->limits, true)['max_users'], 'licence:apply wrote the licence\'s limits');
            $this->assertTrue((new SingleBusinessGuard)->installed());

            $marker = json_decode((string) file_get_contents($work.'/installed.json'), true);
            $this->assertSame(['edition' => 'standalone', 'company_id' => (int) $company->id, 'licence_id' => 'install-test-licence'],
                array_intersect_key($marker, array_flip(['edition', 'company_id', 'licence_id'])));
            $this->assertSame('0600', substr(sprintf('%o', fileperms($work.'/installed.json')), -4));

            $this->expectExceptionMessage('already complete');
            $installer->advance(self::DETAILS);
        } finally {
            InstalledLicence::forget();
            DB::purge('mysql');
            config(['database.connections.mysql.database' => $original]);
            DB::statement("DROP DATABASE IF EXISTS `{$database}`");
            File::deleteDirectory($work);
        }
    }

    public function test_both_env_files_share_one_key_and_round_trip(): void
    {
        $dir = storage_path('framework/testing/env-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($dir.'/budget_pro');
        File::ensureDirectoryExists($dir.'/budget_pro_web');
        try {
            $environments = StandaloneEnvironment::settings([
                'app_url' => 'https://shop.example.co.ug/', 'web_root' => $dir.'/public_html/shop', 'kernel_path' => $dir.'/budget_pro',
                'web_path' => $dir.'/budget_pro_web', 'secure' => true,
                'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'acct_shop', 'db_user' => 'acct_user', 'db_password' => 'p$ss "w#rd\' \\x',
                'business_name' => self::DETAILS['business_name'], 'app_key' => StandaloneEnvironment::newKey(), 'data_key' => StandaloneEnvironment::newKey(),
                'mail_host' => 'server123.host.example', 'mail_port' => '465', 'mail_user' => 'shop@example.co.ug', 'mail_password' => '$ecret#', 'mail_from' => 'shop@example.co.ug',
            ]);
            foreach (['budget_pro' => 'kernel', 'budget_pro_web' => 'web'] as $folder => $side) {
                (new EnvironmentWriter)->write("{$dir}/{$folder}/.env", $environments[$side]);
                $this->assertSame($environments[$side], Dotenv::parse((string) file_get_contents("{$dir}/{$folder}/.env")), $side);
            }
            [$kernel, $web] = [$environments['kernel'], $environments['web']];

            $this->assertSame($kernel['APP_KEY'], $web['APP_KEY']);
            $this->assertSame($kernel['SAAS_DATA_KEY'], $web['SAAS_DATA_KEY']);
            $this->assertNotSame($kernel['APP_KEY'], $kernel['SAAS_DATA_KEY']);
            $this->assertNotSame($kernel['SESSION_COOKIE'], $web['SESSION_COOKIE']);
            foreach (['APP_EDITION' => 'standalone', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://shop.example.co.ug',
                'APP_PUBLIC_PATH' => $dir.'/public_html/shop', 'BUDGETPRO_PATH' => $dir.'/budget_pro', 'BUDGETPRO_MEDIA_ROOT' => $dir.'/public_html/shop/storage',
                'BUDGETPRO_MEDIA_URL' => 'https://shop.example.co.ug/storage', 'SAAS_NEW_UI_URL' => 'https://shop.example.co.ug',
                'SAAS_REDIRECT_TO_NEW_UI' => 'true', 'ADMIN_ROUTE_PREFIX' => 'classic', 'QUEUE_CONNECTION' => 'database', 'DEMO_PUBLIC' => 'false',
                'LEGACY_API_ENABLED' => 'false', 'MAIL_PORT' => '465', 'MAIL_ENCRYPTION' => 'ssl', 'MAIL_SCHEME' => 'smtps',
                'MAIL_FROM_NAME' => self::DETAILS['business_name']] as $key => $value) {
                $this->assertSame($value, $kernel[$key], $key);
                $this->assertSame($value, $web[$key], $key);
            }
            $this->assertArrayNotHasKey('MASTER_PASSWORD', $kernel, 'never written: the master password is off on a buyer\'s server');
            $this->assertArrayNotHasKey('MASTER_PASSWORD', $web);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($dir.'/budget_pro/.env')), -4));
        } finally {
            File::deleteDirectory($dir);
        }
    }
}

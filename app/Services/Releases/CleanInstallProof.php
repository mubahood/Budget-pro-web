<?php

namespace App\Services\Releases;

use App\Services\Install\EnvironmentWriter;
use App\Services\Install\StandaloneEnvironment;
use App\Services\Licensing\Signer;
use App\Support\Licensing\Verifier;
use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Proves a release by installing its ZIP for real (docs/STANDALONE_EDITION_PLAN.md §10 step 6,
 * True-Doctor lesson 6): extracted as on a host (the apps in a home folder, web_root_files moved into
 * public_html), a proof licence for localhost, both .env files as install.php writes them, then
 * `php artisan standalone:install` as a SEPARATE PROCESS inside the extracted budget_pro with a clean
 * environment (an inherited DB_DATABASE would win over the copy's own .env), against an empty MySQL
 * database. Then the result is checked, and the extracted web app's `shop:check` must pass.
 *
 * The database is config edition.test_database: a named one (cPanel cannot CREATE DATABASE over SQL)
 * is emptied before and after; with no name a throwaway one is created and dropped. Never the
 * application's own database.
 */
class CleanInstallProof
{
    /**
     * @param  callable(string,int):void|null  $progress
     * @return array{database:string,seconds:int,checks:list<string>}
     */
    public function run(string $archive, Signer $signer, ?callable $progress = null): array
    {
        $progress ??= static function (): void {
        };
        $started = microtime(true);
        [$db, $dedicated] = $this->settings();
        $root = storage_path('app/private/release-proofs/'.bin2hex(random_bytes(8)));
        $pdo = $this->connect($db);
        $created = false;
        File::ensureDirectoryExists($root, 0700);
        try {
            if ($dedicated) {
                $progress('Emptying the proof database '.$db['database'], 52);
                $this->emptyDatabase($pdo, $db['database']);
            } else {
                $progress('Creating a throwaway proof database', 52);
                $pdo->exec("CREATE DATABASE `{$db['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $created = true;
            }

            $progress('Extracting the ZIP as on a host', 55);
            $verifier = new Verifier([$signer->keyId() => $signer->publicKey()]);
            (new StandaloneArchive)->verify($archive, $verifier);
            $home = $root.'/home';
            $webRoot = $home.'/public_html';
            File::ensureDirectoryExists($home, 0700);
            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::RDONLY) !== true || ! $zip->extractTo($home) || ! $zip->close()) {
                throw new \RuntimeException('The release could not be extracted for its proof.');
            }
            rename($home.'/'.PackageLayout::WEB_ROOT, $webRoot) || throw new \RuntimeException('The web folder could not be moved into place.');
            (new StandaloneArchive)->verifyExtracted($home, $webRoot, $verifier);
            $kernel = $home.'/'.PackageLayout::KERNEL;
            $web = $home.'/'.PackageLayout::WEB;

            $licence = $signer->issue(['customer' => 'Release proof', 'domains' => ['localhost'], 'updates_until' => '2099-12-31',
                'id' => 'release-proof-'.bin2hex(random_bytes(4))]);
            file_put_contents($kernel.'/storage/app/private/licence.json', json_encode($licence, JSON_THROW_ON_ERROR));
            chmod($kernel.'/storage/app/private/licence.json', 0600);
            $environments = StandaloneEnvironment::settings([
                'app_url' => 'http://localhost', 'web_root' => $webRoot, 'kernel_path' => $kernel, 'web_path' => $web, 'secure' => false,
                'db_host' => $db['host'], 'db_port' => $db['port'], 'db_name' => $db['database'], 'db_user' => $db['user'], 'db_password' => $db['password'],
                'business_name' => 'Release proof', 'app_key' => StandaloneEnvironment::newKey(), 'data_key' => StandaloneEnvironment::newKey(),
            ]);
            foreach ([$kernel => $environments['kernel'], $web => $environments['web']] as $app => $settings) {
                (new EnvironmentWriter)->write($app.'/.env', $settings + ($db['socket'] !== '' ? ['DB_SOCKET' => $db['socket']] : []));
            }
            $answers = $root.'/install.json';
            file_put_contents($answers, json_encode(['business_name' => 'Release Proof Shop', 'business_type' => 'retail', 'country' => 'UG',
                'owner_name' => 'Proof Owner', 'owner_email' => 'proof@release.test', 'owner_phone' => '+256 700 000001',
                'owner_password' => 'Owner-'.bin2hex(random_bytes(12)).'-1'], JSON_THROW_ON_ERROR));
            chmod($answers, 0600);

            $progress('Running the packaged installer (every migration) in its own process', 60);
            $this->artisan($kernel, ['standalone:install', '--config='.$answers], 'The packaged install failed');
            $progress('Checking what the install created', 88);
            $checks = $this->assertInstalled($pdo, $db['database'], $kernel);
            $progress('Running the packaged web app\'s shop:check', 92);
            $this->artisan($web, ['shop:check'], 'The packaged web app\'s shop:check failed');
            $checks[] = 'shop:check passed';

            return ['database' => $db['database'], 'seconds' => (int) round(microtime(true) - $started), 'checks' => $checks];
        } finally {
            try {
                $created ? $pdo->exec("DROP DATABASE IF EXISTS `{$db['database']}`") : $this->emptyDatabase($pdo, $db['database']);
            } finally {
                File::deleteDirectory($root);
            }
        }
    }

    /** @return array{0:array{host:string,port:string,user:string,password:string,database:string,socket:string},1:bool} the settings, and whether the database is a dedicated one */
    public function settings(): array
    {
        $c = (array) config('edition.test_database', []);
        if (trim((string) ($c['host'] ?? '')) === '' || trim((string) ($c['user'] ?? '')) === '') {
            throw new \RuntimeException('Configure the proof database first (RELEASE_TEST_DB_HOST, RELEASE_TEST_DB_USER and RELEASE_TEST_DB_DATABASE).');
        }
        $name = trim((string) ($c['database'] ?? ''));
        $dedicated = $name !== '';
        $name = $dedicated ? $name : 'bp_release_'.bin2hex(random_bytes(6));
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \RuntimeException('The proof database name may only hold letters, digits and underscores.');
        }
        $own = (string) config('database.connections.'.config('database.default').'.database');
        if (strcasecmp($own, $name) === 0) {
            throw new \RuntimeException('The proof database must not be the application\'s own database: the proof empties it.');
        }

        return [['host' => (string) $c['host'], 'port' => (string) (($c['port'] ?? null) ?: '3306'), 'user' => (string) $c['user'],
            'password' => (string) ($c['password'] ?? ''), 'database' => $name, 'socket' => (string) ($c['socket'] ?? '')], $dedicated];
    }

    private function connect(array $db): PDO
    {
        $dsn = $db['socket'] !== '' ? "mysql:unix_socket={$db['socket']};charset=utf8mb4" : "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";

        return new PDO($dsn, $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return list<string> */
    private function assertInstalled(PDO $pdo, string $database, string $kernel): array
    {
        $count = fn (string $sql): int => (int) $pdo->query(str_replace('{db}', "`{$database}`", $sql))->fetchColumn();
        $expect = [
            'one company' => [$count('SELECT COUNT(*) FROM {db}.companies'), 1],
            'one owner' => [$count('SELECT COUNT(*) FROM {db}.admin_users u JOIN {db}.companies c ON c.owner_id = u.id AND u.company_id = c.id'), 1],
            'one user' => [$count('SELECT COUNT(*) FROM {db}.admin_users'), 1],
            'the standalone plan subscription' => [$count("SELECT COUNT(*) FROM {db}.subscriptions s JOIN {db}.plans p ON p.id = s.plan_id WHERE p.slug = 'standalone' AND s.status = 'active'"), 1],
        ];
        foreach ($expect as $what => [$actual, $wanted]) {
            if ($actual !== $wanted) {
                throw new \RuntimeException("The packaged install did not leave {$what} (found {$actual}).");
            }
        }
        foreach (['product_templates' => 'product templates', 'admin_roles' => 'roles'] as $table => $what) {
            if ($count("SELECT COUNT(*) FROM {db}.{$table}") < 1) {
                throw new \RuntimeException("The packaged install did not seed the {$what}.");
            }
        }
        $marker = json_decode((string) @file_get_contents($kernel.'/storage/app/private/installed.json'), true);
        if (! is_array($marker) || ($marker['edition'] ?? null) !== 'standalone' || ! is_int($marker['company_id'] ?? null)) {
            throw new \RuntimeException('The packaged install did not write installed.json.');
        }

        return ['one company', 'one owner', 'standalone plan subscription', 'product templates', 'roles', 'installed.json'];
    }

    /** @param list<string> $arguments */
    private function artisan(string $app, array $arguments, string $failure): void
    {
        // A clean environment: this worker's own APP_* / DB_* values (Laravel puts its .env into getenv()) would
        // otherwise win over the extracted copy's .env, and the "proof" would migrate the live database.
        $environment = array_fill_keys(array_keys(getenv() + $_ENV + array_filter($_SERVER, 'is_string')), false);
        foreach (['PATH', 'HOME', 'TMPDIR', 'TEMP', 'TMP', 'LANG', 'USER'] as $keep) {
            unset($environment[$keep]);
        }
        $process = new Process([PHP_BINARY, 'artisan', ...$arguments, '--no-ansi', '--no-interaction'], $app, $environment);
        $process->setTimeout(600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException($failure.': '.mb_substr(trim($process->getOutput()."\n".$process->getErrorOutput()), -2000));
        }
    }

    /** Every table and view of the proof database, and nothing else anywhere. */
    private function emptyDatabase(PDO $pdo, string $database): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $rows = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
            $rows->execute([$database]);
            foreach ($rows->fetchAll(PDO::FETCH_NUM) as [$table, $type]) {
                $name = str_replace('`', '``', (string) $table);
                $pdo->exec(($type === 'VIEW' ? 'DROP VIEW' : 'DROP TABLE')." IF EXISTS `{$database}`.`{$name}`");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}

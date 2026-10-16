<?php

namespace Tests\Feature\Standalone;

use App\Services\Install\DatabaseBackup;
use App\Services\Install\InstallationBackups;
use App\Support\Edition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * The standalone backups (docs/STANDALONE_EDITION_PLAN.md §12.4), on a throwaway MySQL database
 * created and dropped here — never budget_pro_test's own tables.
 */
class BackupTest extends TestCase
{
    private string $database;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = 'bp_backup_probe_'.bin2hex(random_bytes(4));
        DB::statement("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.backup_probe' => ['database' => $this->database] + config('database.connections.mysql')]);
        $this->work = storage_path('framework/testing/standalone-backup-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->work);
    }

    protected function tearDown(): void
    {
        DB::purge('backup_probe');
        DB::statement("DROP DATABASE IF EXISTS `{$this->database}`");
        File::deleteDirectory($this->work);
        parent::tearDown();
    }

    public function test_a_mysql_database_survives_a_dump_and_restore_with_its_rows_and_triggers(): void
    {
        $db = DB::connection('backup_probe');
        $db->statement('CREATE TABLE shops (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL,
            note TEXT NULL, total DECIMAL(12,2) NOT NULL DEFAULT 0, data JSON NULL, raw VARBINARY(32) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, name_upper VARCHAR(100) AS (UPPER(name)) STORED) ENGINE=InnoDB');
        $db->statement('CREATE TABLE sales (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL,
            body TEXT NOT NULL, FOREIGN KEY (shop_id) REFERENCES shops (id)) ENGINE=InnoDB');
        $db->unprepared("CREATE TRIGGER probe_no_delete BEFORE DELETE ON shops FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kept; never deleted'");
        $db->unprepared('CREATE TRIGGER probe_trim BEFORE INSERT ON sales FOR EACH ROW BEGIN SET NEW.body = TRIM(NEW.body); SET NEW.shop_id = NEW.shop_id; END');

        $awkward = "O'Brien \"quoted\" back\\slash\nnew line\n-- bp:statement\n; DROP TABLE shops; \u{1F34C} \0 end";
        $db->table('shops')->insert([
            ['name' => 'Fresh Mart', 'note' => $awkward, 'total' => '1234.50', 'data' => '{"a": [1, "x"]}', 'raw' => random_bytes(16)],
            ['name' => 'Corner', 'note' => null, 'total' => '0.00', 'data' => null, 'raw' => "\xff\xfe\x00\x80"],
        ]);
        $db->table('sales')->insert(array_map(fn ($i) => ['shop_id' => 1 + $i % 2, 'body' => "sale {$i} {$awkward}"], range(1, 1203)));
        $before = $this->snapshot();

        $dump = (new DatabaseBackup('backup_probe'))->dump($this->work.'/probe');
        $this->assertStringEndsWith('.sql.gz', $dump);
        $this->assertSame(0600, fileperms($dump) & 0777);

        // Plain SQL that mysql and phpMyAdmin restore too: every statement ends in ";".
        $sql = (string) gzdecode((string) file_get_contents($dump));
        foreach (array_filter(array_map('trim', explode("\n-- bp:statement\n", $sql))) as $statement) {
            $this->assertStringEndsWith(';', $statement);
        }
        $this->assertSame(3, substr_count($sql, 'INSERT INTO `sales`'), '1203 rows in batches of 500');
        $this->assertStringContainsString("DELIMITER ;;\nCREATE TRIGGER `probe_trim`", $sql);
        $this->assertDoesNotMatchRegularExpression('/INSERT INTO `shops` \([^)]*name_upper/', $sql, 'generated columns are not inserted');

        // Damage it: rows gone, a value changed, a table added since.
        $db->table('sales')->delete();
        $db->table('shops')->where('id', 2)->update(['name' => 'Changed']);
        $db->statement('CREATE TABLE added_later (id INT PRIMARY KEY)');
        $mode = $db->selectOne('SELECT @@SESSION.sql_mode AS m')->m;

        (new DatabaseBackup('backup_probe'))->restore($dump);

        $this->assertSame($mode, $db->selectOne('SELECT @@SESSION.sql_mode AS m')->m);
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $db->select("SHOW TABLES LIKE 'added_later'"));
        $this->assertCount(2, $db->select('SHOW TRIGGERS'));

        try {
            $db->table('shops')->where('id', 1)->delete();
            $this->fail('The restored trigger should refuse the delete.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Kept; never deleted', $e->getMessage());
        }
        $db->table('sales')->insert(['shop_id' => 2, 'body' => '  padded  ']);
        $this->assertSame('padded', $db->table('sales')->orderByDesc('id')->value('body'));
        $this->expectException(QueryException::class); // the foreign key came back too
        $db->table('sales')->insert(['shop_id' => 999, 'body' => 'orphan']);
    }

    public function test_the_installation_backup_zips_database_settings_and_files_and_keeps_seven(): void
    {
        DB::connection('backup_probe')->statement('CREATE TABLE notes (id INT PRIMARY KEY, body TEXT)');
        DB::connection('backup_probe')->table('notes')->insert(['id' => 1, 'body' => 'kept']);

        $put = function (string $relative, string $contents): string {
            File::ensureDirectoryExists(dirname($this->work.'/'.$relative));
            file_put_contents($this->work.'/'.$relative, $contents);

            return $this->work.'/'.$relative;
        };
        config([
            'edition.licence_path' => $put('private/licence.json', '{"licence":{}}'),
            'edition.installed_path' => $put('private/installed.json', '{"edition":"standalone"}'),
        ]);
        $put('media/products/apple.jpg', 'jpeg');
        $put('media/logos/shop.png', 'png');
        $put('media/receipts/2026/10/r1.jpg', 'receipt');
        foreach (range(1, 8) as $i) {
            touch($put(sprintf('backups/budget-pro-backup-202001%02d-000000.zip', $i), 'old'), strtotime("2020-01-0{$i}"));
        }
        $put('backups/not-a-backup.zip', 'other');

        $backups = $this->backups();
        $made = $backups->create();

        $name = basename($made['path']);
        $this->assertMatchesRegularExpression('/^budget-pro-backup-\d{8}-\d{6}\.zip$/', $name);
        $this->assertSame(0600, fileperms($made['path']) & 0777);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($made['path']));
        $entries = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        sort($entries);
        $this->assertSame([
            'RESTORE.txt', 'database.sql.gz',
            'files/logos/shop.png', 'files/products/apple.jpg', 'files/receipts/2026/10/r1.jpg',
            'settings/installed.json', 'settings/kernel.env', 'settings/licence.json', 'settings/web.env',
        ], $entries);
        $this->assertSame("APP_KEY=base64:web\n", $zip->getFromName('settings/web.env'));
        $this->assertStringContainsString('KEEP APP_KEY', $zip->getFromName('RESTORE.txt'));
        $this->assertStringContainsString("'kept'", (string) gzdecode($zip->getFromName('database.sql.gz')));
        $zip->close();

        $list = $backups->list();
        $this->assertCount(InstallationBackups::KEEP, $list);
        $this->assertSame($name, $list[0]['name']);
        $this->assertFileDoesNotExist($this->work.'/backups/budget-pro-backup-20200101-000000.zip');
        $this->assertFileDoesNotExist($this->work.'/backups/budget-pro-backup-20200102-000000.zip');
        $this->assertFileExists($this->work.'/backups/not-a-backup.zip');
        $this->assertSame([], glob($this->work.'/backups/work-*'));

        $this->assertSame($made['path'], $backups->path($name));
        $this->assertNull($backups->path('../../.env'));
        $this->assertNull($backups->path('not-a-backup.zip'));
        $this->assertNull($backups->path('budget-pro-backup-20990101-000000.zip'));
        $this->assertLessThan(60, $backups->newestAge());
    }

    public function test_the_command_runs_only_on_a_standalone_copy(): void
    {
        config(['edition.name' => Edition::SAAS]);
        $this->artisan('standalone:backup')->assertFailed();

        config(['edition.name' => Edition::STANDALONE]);
        $this->app->instance(InstallationBackups::class, $this->backups());
        $this->artisan('standalone:backup')->expectsOutputToContain('Backup written: budget-pro-backup-')->assertSuccessful();
        $this->assertCount(1, glob($this->work.'/backups/budget-pro-backup-*.zip'));
    }

    private function backups(): InstallationBackups
    {
        file_put_contents($this->work.'/web.env', "APP_KEY=base64:web\n");
        file_put_contents($this->work.'/kernel.env', "APP_KEY=base64:kernel\n");
        File::ensureDirectoryExists($this->work.'/media');

        return new InstallationBackups(new DatabaseBackup('backup_probe'), $this->work.'/web.env', $this->work.'/media', $this->work.'/backups', $this->work.'/kernel.env');
    }

    /** @return array<string,array> every row of both tables, in id order */
    private function snapshot(): array
    {
        $db = DB::connection('backup_probe');

        return [
            'shops' => array_map(fn ($r) => (array) $r + ['raw_hex' => bin2hex((string) $r->raw)], $db->table('shops')->orderBy('id')->get()->all()),
            'sales' => array_map(fn ($r) => (array) $r, $db->table('sales')->orderBy('id')->get()->all()),
        ];
    }
}

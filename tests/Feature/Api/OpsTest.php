<?php

namespace Tests\Feature\Api;

use App\Services\Ops\BackupService;
use App\Services\Ops\TenantDataService;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/** Plan Part D (P4-6): request ids, error tracking, backups + drill, tenant export and deletion. */
class OpsTest extends ApiTestCase
{
    public function test_every_response_carries_a_request_id_and_errors_are_grouped(): void
    {
        $this->getJson('/api/v1/plans')->assertOk()->assertHeader('X-Request-Id');
        $this->getJson('/api/v1/plans', ['X-Request-Id' => 'abc-123'])->assertHeader('X-Request-Id', 'abc-123');

        config(['app.track_errors_in_tests' => true]);
        $boom = fn () => new \RuntimeException('Printer driver exploded');
        ErrorTracker::capture($boom());
        ErrorTracker::capture($boom());
        ErrorTracker::capture(\App\Exceptions\BusinessRuleException::make('insufficient_stock', 'Not enough'));
        $row = DB::table('error_events')->where('message', 'Printer driver exploded')->first();
        $this->assertNotNull($row);
        $this->assertSame(1, DB::table('error_events')->where('message', 'Printer driver exploded')->count(), 'same place = one group');
        $this->assertSame(2, (int) $row->count);
        $this->assertFalse(DB::table('error_events')->where('message', 'Not enough')->exists(), 'business-rule refusals are not errors');
    }

    public function test_backup_file_and_drill(): void
    {
        $bin = (string) config('backup.mysqldump');
        exec('command -v '.escapeshellarg($bin).' 2>/dev/null', $o, $code);
        if ($code !== 0 && ! is_executable($bin)) {
            $this->markTestSkipped('mysqldump is not installed here (set BACKUP_MYSQLDUMP).');
        }
        config(['backup.path' => storage_path('app/backups-test')]);
        $b = app(BackupService::class)->run();
        $this->assertTrue($b['ok'], json_encode($b));
        $this->assertGreaterThan(50, $b['tables']);
        $d = app(BackupService::class)->drill();
        $this->assertTrue($d['ok'], json_encode($d));
        $this->assertSame(['backup', 'drill'], DB::table('backup_runs')->orderByDesc('id')->limit(2)->pluck('kind')->reverse()->values()->all());
        array_map('unlink', glob(storage_path('app/backups-test/*.sql.gz')) ?: []);
    }

    public function test_owner_exports_everything_without_secrets_and_can_schedule_and_cancel_deletion(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $this->postJson('/api/v1/customers', ['name' => 'Aunt Sarah', 'phone' => '0772100200'], $h)->assertStatus(201);
        $r = $this->postJson('/api/v1/company/export', [], $h)->assertStatus(201)->json('data');
        $this->assertSame('ready', $r['status']);
        $dl = $this->get("/api/v1/company/exports/{$r['id']}/download", $h)->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'z');
        copy($dl->baseResponse->getFile()->getPathname(), $file); // BinaryFileResponse
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $this->assertStringContainsString('Aunt Sarah', (string) $zip->getFromName('customers.csv'));
        $users = (string) $zip->getFromName('admin_users.csv');
        $this->assertStringNotContainsString('password', strtok($users, "\n"), 'no password hashes in the export');
        $this->assertArrayHasKey('customers', json_decode((string) $zip->getFromName('manifest.json'), true)['tables']);
        $zip->close();
        @unlink($file);

        $this->postJson('/api/v1/company/delete', ['password' => 'nope'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'wrong_password');
        $d = $this->postJson('/api/v1/company/delete', ['password' => 'secret123'], $h)->assertOk()->json('data');
        $this->assertSame('scheduled', $d['status']);
        $this->postJson('/api/v1/company/delete/cancel', [], $h)->assertOk();
        $this->assertSame(0, app(TenantDataService::class)->purgeDue());
        $this->assertTrue(DB::table('companies')->where('id', $t['company_id'])->exists());
    }

    public function test_a_scheduled_deletion_purges_the_shop_after_the_grace_period_and_leaves_others(): void
    {
        $gone = $this->registerTenant();
        $stays = $this->registerTenant();
        $h = $this->auth($gone['token']);
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S', 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $h)->json('data.id');
        $p = $this->postJson('/api/v1/stock-items', ['name' => 'Soda', 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => 10], $h)->json('data');
        $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 2000]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $h)->assertStatus(201);
        $this->postJson('/api/v1/company/delete', ['password' => 'secret123'], $h)->assertOk();

        $this->travel(29)->days();
        $this->assertSame(0, app(TenantDataService::class)->purgeDue(), 'not before the grace period ends');
        $this->travel(2)->days();
        $this->assertSame(1, app(TenantDataService::class)->purgeDue());
        foreach (['companies' => 'id', 'admin_users' => 'company_id', 'stock_items' => 'company_id', 'sale_records' => 'company_id', 'stock_records' => 'company_id', 'payments' => 'company_id', 'locations' => 'company_id'] as $table => $col) {
            $this->assertFalse(DB::table($table)->where($col, $gone['company_id'])->exists(), "{$table} purged");
        }
        $this->assertTrue(DB::table('companies')->where('id', $stays['company_id'])->exists());
        $this->assertTrue(DB::table('admin_users')->where('company_id', $stays['company_id'])->exists());
        $this->assertSame('done', DB::table('data_requests')->where('company_id', $gone['company_id'])->value('status'));
    }
}

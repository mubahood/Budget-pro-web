<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\FinancialRecord;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\Subscription;
use App\Models\SyncBatch;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan §B11 backend sync tests (P1-7): idempotent replay, batch atomicity,
 * seq cursor never skips, isolation, tombstones, missing parents, LWW with
 * skewed clocks, negative-stock policy, numbering, entitlement grace/hold.
 */
class SyncTest extends ApiTestCase
{
    private function tenant(): array
    {
        $t = $this->registerTenant();
        $t['device'] = (string) Str::uuid();
        $t['h'] = $this->auth($t['token']) + ['X-Device-Id' => $t['device']];
        $this->postJson('/api/v1/devices/register', ['device_id' => $t['device'], 'name' => 'Till 1', 'platform' => 'android'], $this->auth($t['token']))->assertOk();

        return $t;
    }

    private function product(array $t, float $qty = 10, float $price = 1000): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $t['h'])->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $t['h'])->json('data.id');
        $item = $this->postJson('/api/v1/stock-items', ['name' => 'P'.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => $price, 'buying_price' => $price * 0.6, 'original_quantity' => $qty], $t['h']);
        $item->assertStatus(201);

        return ['id' => $item->json('data.id'), 'uuid' => $item->json('data.uuid')];
    }

    private function saleBatch(array $product, float $qty, array $extra = []): array
    {
        $saleUuid = (string) Str::uuid();

        return [
            'batch_uuid' => (string) Str::uuid(),
            'kind' => 'sale',
            'ops' => [
                ['op_uuid' => (string) Str::uuid(), 'table' => 'sales', 'uuid' => $saleUuid, 'action' => 'insert', 'client_updated_at' => SyncSequence::nowMs(),
                    'data' => array_merge(['provisional_number' => 'RCP-D1-'.random_int(100000, 999999), 'occurred_at' => SyncSequence::nowMs(), 'has_payment_ops' => 1,
                        'items' => [['product_uuid' => $product['uuid'], 'quantity' => (string) $qty]]], $extra)],
                ['op_uuid' => (string) Str::uuid(), 'table' => 'payments', 'uuid' => (string) Str::uuid(), 'action' => 'insert',
                    'data' => ['sale_uuid' => $saleUuid, 'method' => 'cash', 'amount' => (string) ($qty * 1000), 'received_at' => SyncSequence::nowMs()]],
            ],
        ];
    }

    public function test_device_registration_is_stable_and_returns_entitlements(): void
    {
        $t = $this->registerTenant();
        $id = (string) Str::uuid();
        $a = $this->postJson('/api/v1/devices/register', ['device_id' => $id], $this->auth($t['token']))->assertOk();
        $b = $this->postJson('/api/v1/devices/register', ['device_id' => $id, 'name' => 'Renamed'], $this->auth($t['token']))->assertOk();
        $c = $this->postJson('/api/v1/devices/register', ['device_id' => (string) Str::uuid()], $this->auth($t['token']))->assertOk();

        $this->assertSame('D1', $a->json('data.number_prefix'));
        $this->assertSame('D1', $b->json('data.number_prefix'), 're-registering keeps the prefix');
        $this->assertSame('D2', $c->json('data.number_prefix'));
        $this->assertSame('active', $a->json('data.entitlements.state'));
        $this->assertIsInt($a->json('data.server_time'));
        $this->getJson('/api/v1/auth/me', $this->auth($t['token']))->assertOk()->assertJsonPath('data.entitlements.state', 'active');
    }

    public function test_push_requires_registered_device(): void
    {
        $t = $this->registerTenant();
        $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [['table' => 'categories', 'uuid' => (string) Str::uuid(), 'data' => ['name' => 'x']]]]]],
            $this->auth($t['token']) + ['X-Device-Id' => 'not-registered-123'])->assertStatus(422)->assertJsonPath('errors.code', 'device_not_registered');
    }

    public function test_offline_sale_batch_is_applied_numbered_and_idempotent(): void
    {
        $t = $this->tenant();
        $p = $this->product($t, 10);
        $batch = $this->saleBatch($p, 3);

        $res = $this->postJson('/api/v1/sync/push', ['device_time' => SyncSequence::nowMs(), 'batches' => [$batch]], $t['h'])->assertOk();
        $r = $res->json('data.results.0');
        $saleUuid = $batch['ops'][0]['uuid'];
        $this->assertSame('applied', $r['status']);
        $this->assertSame('RCP-'.now()->format('Y').'-000001', $r['assigned']['sales'][$saleUuid]['receipt_number']);
        $this->assertSame('7.000', $r['derived']['products'][$p['uuid']]['current_quantity']);

        $sale = SaleRecord::withoutGlobalScopes()->where('uuid', $saleUuid)->first();
        $this->assertSame($t['device'], $sale->device_id);
        $this->assertSame($batch['ops'][0]['data']['provisional_number'], $sale->provisional_number);
        $this->assertSame('Paid', $sale->payment_status);
        $this->assertSame(3000.0, (float) FinancialRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->sum('amount'));

        // Replay: same result, nothing applied twice.
        $again = $this->postJson('/api/v1/sync/push', ['batches' => [$batch]], $t['h'])->assertOk();
        $this->assertTrue($again->json('data.results.0.replayed'));
        $this->assertSame(7.0, (float) StockItem::withoutGlobalScopes()->find($p['id'])->current_quantity);
        $this->assertSame(1, SaleRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->count());
        $this->assertSame(1, SyncBatch::where('company_id', $t['company_id'])->count());
    }

    public function test_one_bad_op_rolls_back_the_whole_batch(): void
    {
        $t = $this->tenant();
        $p = $this->product($t, 10);
        $batch = $this->saleBatch($p, 2);
        $batch['ops'][] = ['op_uuid' => (string) Str::uuid(), 'table' => 'stock_movements', 'uuid' => (string) Str::uuid(), 'action' => 'insert',
            'data' => ['product_uuid' => (string) Str::uuid(), 'type' => 'damage', 'quantity' => '-1']];

        $r = $this->postJson('/api/v1/sync/push', ['batches' => [$batch]], $t['h'])->assertOk()->json('data.results.0');
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('missing_parent', collect($r['ops'])->last()['code']);
        $this->assertSame(10.0, (float) StockItem::withoutGlobalScopes()->find($p['id'])->current_quantity, 'sale rolled back with the batch');
        $this->assertSame(0, SaleRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->count());
        $this->assertSame(0, FinancialRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->count());

        // Fixed batch with the same uuid is accepted afterwards (rejections are not final).
        array_pop($batch['ops']);
        $this->assertSame('applied', $this->postJson('/api/v1/sync/push', ['batches' => [$batch]], $t['h'])->json('data.results.0.status'));
    }

    public function test_offline_oversell_is_accepted_and_flagged_never_rejected(): void
    {
        $t = $this->tenant();
        $p = $this->product($t, 2);

        $r = $this->postJson('/api/v1/sync/push', ['batches' => [$this->saleBatch($p, 5)]], $t['h'])->assertOk()->json('data.results.0');
        $this->assertSame('applied', $r['status']);
        $this->assertCount(1, $r['stock_exceptions']);
        $this->assertSame('-3.000', $r['derived']['products'][$p['uuid']]['current_quantity']);
        $this->assertTrue((bool) SaleRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->value('stock_exception'));

        $conflicts = $this->getJson('/api/v1/sync/conflicts', $t['h'])->assertOk()->json('data');
        $this->assertCount(1, $conflicts);
        $this->assertSame('stock_exception', $conflicts[0]['code']);
        $this->assertStringContainsString('Sold 5', $conflicts[0]['title']);

        // "Mark counted": 4 actually on the shelf.
        $this->postJson('/api/v1/sync/conflicts/'.$conflicts[0]['id'].'/resolve', ['choice' => 'counted', 'counted_quantity' => 4], $t['h'])
            ->assertOk()->assertJsonPath('data.state', 'resolved');
        $this->assertSame(4.0, (float) StockItem::withoutGlobalScopes()->find($p['id'])->current_quantity);
    }

    public function test_seq_cursor_never_skips_rows_with_identical_timestamps(): void
    {
        $t = $this->tenant();
        $ts = SyncSequence::nowMs();
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['uuid' => (string) Str::uuid(), 'company_id' => $t['company_id'], 'name' => 'Cat '.$i, 'server_seq' => SyncSequence::next(),
                'client_created_at' => $ts, 'client_updated_at' => $ts, 'version' => 1, 'is_deleted' => 0, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('stock_categories')->insert($chunk);
        }

        $seen = [];
        $since = 0;
        $pages = 0;
        do {
            $res = $this->getJson('/api/v1/sync/pull?table=categories&limit=200&since_seq='.$since, $t['h'])->assertOk();
            foreach ($res->json('data.rows') as $row) {
                $seen[$row['uuid']] = true;
            }
            $since = $res->json('data.next_seq');
            $pages++;
        } while ($res->json('data.has_more') && $pages < 20);

        $this->assertCount(1000, $seen, 'every row delivered exactly once');
        $this->assertSame(5, $pages);
    }

    public function test_pull_is_tenant_isolated_and_propagates_tombstones(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $catA = $this->postJson('/api/v1/stock-categories', ['name' => 'Only A'], $a['h'])->json('data');

        $rowsB = $this->getJson('/api/v1/sync/pull?table=categories&since_seq=0', $b['h'])->json('data.rows');
        $this->assertNotContains($catA['uuid'], array_column($rowsB, 'uuid'));

        $before = $this->getJson('/api/v1/sync/pull?table=categories&since_seq=0', $a['h'])->json('data.next_seq');
        $this->deleteJson('/api/v1/stock-categories/'.$catA['id'], [], $a['h'])->assertOk();
        $delta = $this->getJson('/api/v1/sync/pull?table=categories&since_seq='.$before, $a['h'])->json('data.rows');
        $this->assertSame([$catA['uuid']], array_column($delta, 'uuid'));
        $this->assertSame(1, $delta[0]['is_deleted']);
        $this->getJson('/api/v1/stock-categories/'.$catA['id'], $a['h'])->assertStatus(404);
        $this->assertTrue(StockCategory::withoutGlobalScopes()->where('uuid', $catA['uuid'])->exists(), 'tombstone, not hard delete');
    }

    public function test_master_data_lww_with_skewed_clocks_raises_conflict_not_data_loss(): void
    {
        $t = $this->tenant();
        $uuid = (string) Str::uuid();
        $push = fn (array $op) => $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master',
            'ops' => [$op + ['op_uuid' => (string) Str::uuid(), 'table' => 'categories', 'uuid' => $uuid]]]]], $t['h'])->json('data.results.0');

        $this->assertSame('applied', $push(['action' => 'insert', 'client_updated_at' => 1000, 'version' => 0, 'data' => ['name' => 'Drinks']])['status']);
        // Web edit (server version moves on, clock = now).
        $cat = StockCategory::withoutGlobalScopes()->where('uuid', $uuid)->first();
        $cat->name = 'Drinks (web)';
        $cat->save();

        // Device with an old base version and an old clock edits the same row → conflict, server row kept.
        $r = $push(['action' => 'update', 'client_updated_at' => 2000, 'version' => 1, 'data' => ['name' => 'Drinks (phone)']]);
        $this->assertSame('conflict', $r['status']);
        $this->assertSame('stale_version', $r['ops'][0]['code']);
        $this->assertSame('Drinks (web)', $r['ops'][0]['server_data']['name']);
        $this->assertSame('Drinks (web)', $cat->fresh()->name);

        // Owner chooses "keep mine" in the inbox.
        $this->postJson('/api/v1/sync/conflicts/'.$r['ops'][0]['conflict_id'].'/resolve', ['choice' => 'mine'], $t['h'])->assertOk();
        $this->assertSame('Drinks (phone)', $cat->fresh()->name);

        // A device with a *current* version and a skewed-future clock simply wins.
        $v = (int) $cat->fresh()->version;
        $this->assertSame('applied', $push(['action' => 'update', 'client_updated_at' => 9999999999999, 'version' => $v, 'data' => ['name' => 'Latest']])['status']);
        $this->assertSame('Latest', $cat->fresh()->name);
    }

    public function test_missing_parent_is_rejected_not_null_fk(): void
    {
        $t = $this->tenant();
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => 'sub_categories', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['name' => 'Orphan', 'category_uuid' => (string) Str::uuid(), 'measurement_unit' => 'pcs']],
        ]]]], $t['h'])->json('data.results.0');
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('missing_parent', $r['ops'][0]['code']);
        $this->assertSame('category_uuid', $r['ops'][0]['parent']);
    }

    public function test_events_are_immutable_and_unknown_tables_rejected(): void
    {
        $t = $this->tenant();
        $p = $this->product($t, 10);
        $batch = $this->saleBatch($p, 1);
        $this->postJson('/api/v1/sync/push', ['batches' => [$batch]], $t['h']);
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => 'sales', 'uuid' => $batch['ops'][0]['uuid'], 'action' => 'update', 'data' => ['total' => '1']],
        ]]]], $t['h'])->json('data.results.0');
        $this->assertSame('immutable_event', $r['ops'][0]['code']);

        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => 'nope', 'uuid' => (string) Str::uuid(), 'data' => []],
        ]]]], $t['h'])->json('data.results.0');
        $this->assertSame('unknown_table', $r['ops'][0]['code']);

        // Void is its own op and restores stock.
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => 'sales', 'uuid' => $batch['ops'][0]['uuid'], 'action' => 'void', 'data' => ['reason' => 'wrong item']],
        ]]]], $t['h'])->json('data.results.0');
        $this->assertSame('applied', $r['status']);
        $this->assertSame(10.0, (float) StockItem::withoutGlobalScopes()->find($p['id'])->current_quantity);
    }

    public function test_restock_movement_and_web_edits_flow_to_devices(): void
    {
        $t = $this->tenant();
        $p = $this->product($t, 1);
        $since = $this->getJson('/api/v1/sync/pull?table=products&since_seq=0', $t['h'])->json('data.next_seq');

        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'movement', 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => 'stock_movements', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['product_uuid' => $p['uuid'], 'type' => 'purchase_receipt', 'quantity' => '24']],
        ]]]], $t['h'])->json('data.results.0');
        $this->assertSame('25.000', $r['derived']['products'][$p['uuid']]['current_quantity']);

        // Admin/API edit of the product (price) reaches the pull stream too.
        $this->putJson('/api/v1/stock-items/'.$p['id'], ['selling_price' => 1500], $t['h'])->assertOk();
        $rows = $this->getJson('/api/v1/sync/pull?table=products&since_seq='.$since, $t['h'])->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame('1500.00', $rows[0]['selling_price']);
        $this->assertSame('25.000', $rows[0]['current_quantity']);
    }

    public function test_multi_table_pull_and_bootstrap(): void
    {
        $t = $this->tenant();
        $this->product($t, 3);
        $res = $this->getJson('/api/v1/sync/pull?tables=categories,sub_categories,products&since_seq=0', $t['h'])->assertOk();
        $this->assertCount(1, $res->json('data.tables.products.rows'));
        $this->assertCount(1, $res->json('data.tables.categories.rows'));
        $this->assertNotNull($res->json('data.tables.products.rows.0.sub_category_uuid'));

        $boot = $this->postJson('/api/v1/sync/bootstrap', ['tables' => ['products', 'financial_periods', 'farm_types']], $t['h'])->assertOk();
        $this->assertCount(1, $boot->json('data.tables.products.rows'));
        $this->assertCount(1, $boot->json('data.tables.financial_periods.rows'));
        $this->assertGreaterThan(0, $boot->json('data.seq'));
    }

    public function test_expired_tenant_batches_are_held_then_applied_on_renewal(): void
    {
        $t = $this->tenant();
        $p = $this->product($t, 10);
        $sub = Subscription::where('company_id', $t['company_id'])->first();
        $sub->status = 'expired';
        $sub->trial_ends_at = now()->subDays(30);
        $sub->ends_at = now()->subDays(30);
        $sub->save();
        Company::where('id', $t['company_id'])->update(['license_expire' => now()->subDays(30)]);

        $batch = $this->saleBatch($p, 2);
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [$batch]], $t['h'])->assertOk()->json('data');
        $this->assertSame('held', $r['results'][0]['status']);
        $this->assertSame('expired', $r['entitlement_state']);
        $this->assertSame(10.0, (float) StockItem::withoutGlobalScopes()->find($p['id'])->current_quantity);

        $this->seed(\Database\Seeders\PlanSeeder::class);
        $company = Company::find($t['company_id']);
        $company->activateSubscription(\App\Models\Plan::where('slug', 'business')->first(), 'manual');
        $applied = app(\App\Services\Sync\SyncApplier::class)->applyHeld($company->fresh());

        $this->assertSame(1, $applied);
        $this->assertSame(8.0, (float) StockItem::withoutGlobalScopes()->find($p['id'])->current_quantity);
        $this->assertSame('applied', SyncBatch::where('company_id', $t['company_id'])->value('status'));
    }

    public function test_grace_tenant_keeps_syncing(): void
    {
        $t = $this->tenant();
        $p = $this->product($t, 10);
        Subscription::where('company_id', $t['company_id'])->update(['status' => 'expired', 'ends_at' => now()->subDays(2), 'trial_ends_at' => now()->subDays(2)]);
        Company::where('id', $t['company_id'])->update(['license_expire' => now()->subDays(2)]);

        $r = $this->postJson('/api/v1/sync/push', ['batches' => [$this->saleBatch($p, 1)]], $t['h'])->json('data');
        $this->assertSame('applied', $r['results'][0]['status']);
        $this->assertSame('grace', $r['entitlement_state']);
    }

    public function test_revoked_device_cannot_push(): void
    {
        $t = $this->tenant();
        $id = $this->getJson('/api/v1/devices', $t['h'])->json('data.0.id');
        $this->postJson("/api/v1/devices/{$id}/revoke", [], $t['h'])->assertOk();
        $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [['table' => 'categories', 'uuid' => (string) Str::uuid(), 'data' => ['name' => 'x']]]]]], $t['h'])
            ->assertStatus(403)->assertJsonPath('errors.code', 'device_revoked');
    }

    public function test_poultry_rows_are_pullable_by_seq_through_v2(): void
    {
        $t = $this->tenant();
        $uuid = (string) Str::uuid();
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => 'batches', 'uuid' => $uuid, 'action' => 'upsert', 'client_updated_at' => 5000,
                'data' => ['name' => 'Layers', 'type' => 'layer', 'acquired_date' => '2026-08-01', 'start_count' => 100, 'status' => 'active', 'updated_at' => 5000, 'created_at' => 5000]],
        ]]]], $t['h'])->json('data.results.0');
        $this->assertSame('applied', $r['status']);
        $rows = $this->getJson('/api/v1/sync/pull?table=batches&since_seq=0', $t['h'])->json('data.rows');
        $this->assertSame($uuid, $rows[0]['uuid']);
        $this->assertGreaterThan(0, $rows[0]['server_seq']);
        // v1 endpoint still serves the same row (kept one release).
        $this->getJson('/api/v1/poultry/sync/pull?table=batches&since=0', $this->auth($t['token']))->assertOk()->assertJsonPath('data.rows.0.uuid', $uuid);
    }

    public function test_registry_wire_keys_are_unique(): void
    {
        // A duplicated array key silently overwrites the earlier table (it happened once with `customers`).
        $src = file_get_contents(app_path('Services/Sync/SyncRegistry.php'));
        preg_match_all("/^\\s{12}'([a-z_]+)' => \\['model'/m", $src, $m);
        $this->assertSame(count($m[1]), count(array_unique($m[1])), 'duplicate wire keys: '.implode(',', array_diff_assoc($m[1], array_unique($m[1]))));
        $this->assertGreaterThan(30, count($m[1]));
    }
}

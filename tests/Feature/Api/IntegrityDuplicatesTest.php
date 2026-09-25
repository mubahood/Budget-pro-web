<?php

namespace Tests\Feature\Api;

use App\Models\StockItem;
use App\Support\Sync\SyncSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Plan Part D / Appendix E (P4-5): the database refuses orphans and unknown statuses; duplicates from phones are kept and merged. */
class IntegrityDuplicatesTest extends ApiTestCase
{
    public function test_foreign_keys_and_status_checks_are_enforced(): void
    {
        $fks = DB::table('information_schema.referential_constraints')->where('constraint_schema', DB::getDatabaseName())->pluck('constraint_name')->all();
        foreach (['fk_sale_record_items_sale_record_id', 'fk_stock_records_stock_item_id', 'fk_payments_sale_record_id', 'fk_goods_receipt_items_goods_receipt_id', 'fk_stock_levels_location_id'] as $fk) {
            $this->assertContains($fk, $fks);
        }
        try {
            DB::table('sale_record_items')->insert(['company_id' => 1, 'sale_record_id' => 999999999, 'stock_item_id' => null, 'item_name' => 'x', 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('an item pointing at a missing sale must be refused');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->getCode());
        }
        $t = $this->registerTenant();
        $sale = $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 1000]], 'items' => [['stock_item_id' => $this->product($t, 'X')['id'], 'quantity' => 1]]], $this->auth($t['token']))
            ->assertStatus(201)->json('data.id');
        if (\App\Support\SchemaIntegrity::checksSupported()) {
            try {
                DB::table('sale_records')->where('id', $sale)->update(['status' => 'Whatever']);
                $this->fail('the database must refuse an unknown sale status');
            } catch (QueryException $e) {
                $this->assertStringContainsString('chk_sale_records_status', $e->getMessage());
            }
        }
        // On every database the application refuses it.
        $model = \App\Models\SaleRecord::withoutGlobalScopes()->find($sale);
        $model->status = 'Whatever';
        try {
            $model->save();
            $this->fail('the application must refuse an unknown sale status');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('invalid_status', $e->errorCode());
        }
        $this->artisan('schema:integrity')->assertSuccessful();
    }

    private function product(array $t, string $name, array $o = []): array
    {
        $h = $this->auth($t['token']);
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', array_merge(['name' => $name, 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => 10], $o), $h)
            ->assertStatus(201)->json('data');
    }

    public function test_online_creates_refuse_duplicates_but_two_phones_may_create_the_same_customer_and_it_is_merged(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $this->postJson('/api/v1/suppliers', ['name' => 'Mukwano'], $h)->assertStatus(201);
        $this->postJson('/api/v1/suppliers', ['name' => 'Mukwano'], $h)->assertStatus(422);
        $this->postJson('/api/v1/stock-categories', ['name' => 'Drinks'], $h)->assertStatus(201);
        $this->postJson('/api/v1/stock-categories', ['name' => 'Drinks'], $h)->assertStatus(422);
        $p = $this->product($t, 'Soda', ['barcode' => '6001']);
        $this->product($t, 'Soda 2', ['barcode' => '6002']);
        $cat = DB::table('stock_items')->where('id', $p['id'])->value('stock_sub_category_id');
        $this->postJson('/api/v1/stock-items', ['name' => 'Soda again', 'stock_sub_category_id' => $cat, 'selling_price' => 1, 'barcode' => '6001'], $h)->assertStatus(422);

        // Two phones add "Aunt Sarah" with the same number while offline: both kept, owner told once.
        foreach (['p1', 'p2'] as $n) {
            $device = (string) Str::uuid();
            $this->postJson('/api/v1/devices/register', ['device_id' => $device], $h)->assertOk();
            $cust = (string) Str::uuid();
            $sale = (string) Str::uuid();
            $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [
                ['op_uuid' => (string) Str::uuid(), 'table' => 'customers', 'uuid' => $cust, 'action' => 'insert', 'client_updated_at' => SyncSequence::nowMs(), 'data' => ['name' => 'Aunt Sarah', 'phone' => '0772 100 200']],
                ['op_uuid' => (string) Str::uuid(), 'table' => 'sales', 'uuid' => $sale, 'action' => 'insert', 'client_updated_at' => SyncSequence::nowMs(), 'data' => ['customer_uuid' => $cust, 'occurred_at' => SyncSequence::nowMs(),
                    'items' => [['product_uuid' => $p['uuid'], 'quantity' => '1']]]],
            ]]]], $h + ['X-Device-Id' => $device])->assertOk()->json('data.results.0');
            $this->assertSame('applied', $r['status'], json_encode($r));
        }
        $this->assertSame(2, DB::table('customers')->where('company_id', $t['company_id'])->where('name', 'Aunt Sarah')->count());
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $t['company_id'])->where('title', 'like', 'Possible duplicate: Aunt Sarah')->count());

        $groups = collect($this->getJson('/api/v1/duplicates', $h)->assertOk()->json('data'))->where('kind', 'customers')->values();
        $this->assertCount(1, $groups);
        [$keep, $other] = array_column($groups[0]['rows'], 'id');
        $this->postJson('/api/v1/duplicates/merge', ['kind' => 'customers', 'keep_id' => $keep, 'merge_ids' => [$other]], $h)->assertOk()->assertJsonPath('data.merged', 1);
        $this->assertSame(2, DB::table('sale_records')->where('customer_id', $keep)->count(), 'both sales now belong to one customer');
        $this->assertEquals(2000, (float) DB::table('customers')->where('id', $keep)->value('balance'));
        $this->assertSame(1, (int) DB::table('customers')->where('id', $other)->value('is_deleted'), 'the twin is tombstoned so phones drop it');
        $this->assertSame([], collect($this->getJson('/api/v1/duplicates', $h)->json('data'))->where('kind', 'customers')->values()->all());
    }

    public function test_merging_products_adds_up_stock_levels_and_batches(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $a = $this->product($t, 'Panadol', ['track_batches' => true, 'original_quantity' => 0]);
        $b = $this->product($t, 'Panadol tabs', ['track_batches' => true, 'original_quantity' => 0]);
        foreach ([[$a['id'], 10, 'LOT1'], [$b['id'], 4, 'LOT1'], [$b['id'], 3, 'LOT2']] as [$id, $q, $lot]) {
            $this->postJson('/api/v1/goods-receipts', ['items' => [['stock_item_id' => $id, 'quantity' => $q, 'unit_cost' => 500, 'batch_number' => $lot, 'expiry_date' => now()->addYear()->toDateString()]]], $h)->assertStatus(201);
        }
        DB::table('stock_items')->where('id', $b['id'])->update(['barcode' => DB::table('stock_items')->where('id', $a['id'])->value('barcode') ?? 'SAME']);
        DB::table('stock_items')->where('id', $a['id'])->update(['barcode' => 'SAME']);
        DB::table('stock_items')->where('id', $b['id'])->update(['barcode' => 'SAME']);

        $this->postJson('/api/v1/duplicates/merge', ['kind' => 'stock_items', 'keep_id' => $a['id'], 'merge_ids' => [$b['id']]], $h)->assertOk();
        $this->assertEquals(17, (float) StockItem::withoutGlobalScopes()->find($a['id'])->current_quantity);
        $this->assertEquals(17, (float) DB::table('stock_levels')->where('stock_item_id', $a['id'])->sum('quantity'));
        $this->assertSame(0, DB::table('stock_levels')->where('stock_item_id', $b['id'])->count());
        $lots = DB::table('stock_batches')->where('stock_item_id', $a['id'])->pluck('quantity', 'batch_number')->map(fn ($q) => (float) $q)->all();
        $this->assertEquals(['LOT1' => 14.0, 'LOT2' => 3.0], $lots);
        $this->assertSame(3, DB::table('stock_records')->where('stock_item_id', $a['id'])->count(), 'movement history moves with the product');
        $this->postJson('/api/v1/duplicates/merge', ['kind' => 'stock_items', 'keep_id' => $a['id'], 'merge_ids' => [999999]], $h)->assertStatus(422);
    }
}

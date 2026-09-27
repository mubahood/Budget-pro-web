<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\SaleRecord;
use App\Services\Shop\GiftCardService;
use App\Support\StoreFeatures;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server side of the phone's supermarket sync: snapshot tables (tax classes, prices, promotions, batches,
 * markdowns, locations), cash_movements (push + pull), product/customer store fields, pull-many, keyset
 * bootstrap with a history window, and the sale op's new optional fields (old-style ops keep working).
 */
class SyncSupermarketTest extends ApiTestCase
{
    private function tenant(): array
    {
        $t = $this->registerTenant();
        $t['device'] = (string) Str::uuid();
        $t['h'] = $this->auth($t['token']) + ['X-Device-Id' => $t['device']];
        $this->postJson('/api/v1/devices/register', ['device_id' => $t['device'], 'name' => 'Till', 'platform' => 'android'], $this->auth($t['token']))->assertOk();

        return $t;
    }

    private function features(array $t, array $features, array $settings = []): void
    {
        StoreFeatures::update(Company::find($t['company_id']), ['features' => $features, 'settings' => $settings]);
    }

    private function product(array $t, float $qty = 20, float $price = 1000): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $t['h'])->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $t['h'])->json('data.id');
        $item = $this->postJson('/api/v1/stock-items', ['name' => 'P'.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => $price, 'buying_price' => $price * 0.6, 'original_quantity' => $qty], $t['h'])->assertStatus(201);

        return ['id' => $item->json('data.id'), 'uuid' => $item->json('data.uuid')];
    }

    private function push(array $t, array $ops): array
    {
        $ops = array_map(fn ($o) => $o + ['op_uuid' => (string) Str::uuid(), 'action' => 'insert', 'client_updated_at' => SyncSequence::nowMs()], $ops);

        return $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => $ops]]], $t['h'])->assertOk()->json('data.results.0');
    }

    public function test_snapshot_tables_send_the_whole_set_only_when_it_changes(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $p = $this->product($t);
        DB::table('tax_classes')->insert(['company_id' => $t['company_id'], 'name' => 'Standard', 'code' => 'standard', 'rate' => 18, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tax_classes')->insert(['company_id' => $other['company_id'], 'name' => 'Other shop', 'code' => 'standard', 'rate' => 16, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_prices')->insert(['company_id' => $t['company_id'], 'stock_item_id' => $p['id'], 'level' => 'wholesale', 'price' => 800, 'min_qty' => 1]);
        $promo = DB::table('promotions')->insertGetId(['company_id' => $t['company_id'], 'name' => 'Half', 'type' => 'percent_off', 'rules' => '{"percent":50}', 'is_active' => 1]);
        DB::table('promotion_targets')->insert(['promotion_id' => $promo, 'target_type' => 'product', 'target_id' => $p['id']]);
        DB::table('stock_batches')->insert(['company_id' => $t['company_id'], 'stock_item_id' => $p['id'], 'batch_number' => 'B1', 'expiry_date' => now()->addDays(5)->toDateString(), 'quantity' => 4, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_batches')->insert(['company_id' => $t['company_id'], 'stock_item_id' => $p['id'], 'batch_number' => 'EMPTY', 'quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $first = $this->getJson('/api/v1/sync/pull?table=tax_classes&since_seq=0', $t['h'])->assertOk()->json('data');
        $this->assertTrue($first['snapshot']);
        $this->assertCount(1, $first['rows'], 'only this shop\'s classes');
        $this->assertSame('Standard', $first['rows'][0]['name']);
        $this->assertNotEmpty($first['rows'][0]['uuid']);
        $again = $this->getJson('/api/v1/sync/pull?table=tax_classes&since_seq='.$first['next_seq'], $t['h'])->assertOk()->json('data');
        $this->assertSame([], $again['rows']);
        $this->assertTrue($again['unchanged']);
        DB::table('tax_classes')->where('company_id', $t['company_id'])->update(['rate' => 16]);
        $changed = $this->getJson('/api/v1/sync/pull?table=tax_classes&since_seq='.$first['next_seq'], $t['h'])->assertOk()->json('data');
        $this->assertTrue($changed['snapshot']);
        $this->assertEquals(16, $changed['rows'][0]['rate']);
        $this->assertNotSame($first['next_seq'], $changed['next_seq']);

        $many = $this->postJson('/api/v1/sync/pull-many', ['tables' => ['products' => 0, 'product_prices' => 0, 'promotions' => 0, 'stock_batches' => 0, 'locations' => 0, 'nope' => 0], 'limit' => 100], $t['h'])
            ->assertOk()->json('data.tables');
        $this->assertCount(1, $many['products']['rows']);
        $this->assertSame($p['uuid'], $many['product_prices']['rows'][0]['product_uuid']);
        $this->assertSame(50, $many['promotions']['rows'][0]['rules']['percent']);
        $this->assertSame($p['uuid'], $many['promotions']['rows'][0]['targets'][0]['target_uuid']);
        $this->assertCount(1, $many['stock_batches']['rows'], 'empty batches are left out');
        $this->assertSame('unknown_table', $many['nope']['error']);

        // A deleted promotion changes the set: the device gets the (now empty) set and clears its copy.
        DB::table('promotion_targets')->where('promotion_id', $promo)->delete();
        DB::table('promotions')->where('id', $promo)->delete();
        $gone = $this->postJson('/api/v1/sync/pull-many', ['tables' => ['promotions' => $many['promotions']['next_seq']]], $t['h'])->assertOk()->json('data.tables.promotions');
        $this->assertTrue($gone['snapshot']);
        $this->assertSame([], $gone['rows']);

        // Snapshot tables are pull-only.
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [['table' => 'tax_classes', 'uuid' => (string) Str::uuid(), 'data' => ['name' => 'x']]]]]], $t['h'])
            ->assertOk()->json('data.results.0');
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('read_only_table', $r['ops'][0]['code']);
    }

    public function test_bootstrap_pages_by_keyset_windows_event_history_and_keeps_master_data(): void
    {
        $t = $this->tenant();
        $p = $this->product($t, 50);
        foreach (range(1, 3) as $i) {
            $this->push($t, [['table' => 'sales', 'uuid' => (string) Str::uuid(), 'data' => ['occurred_at' => SyncSequence::nowMs(), 'amount_paid' => 1000, 'items' => [['product_uuid' => $p['uuid'], 'quantity' => 1]]]]]);
        }
        // An old paid sale (outside the window) and an old unpaid one (kept: money is still owed).
        $old = SaleRecord::where('company_id', $t['company_id'])->orderBy('id')->first();
        DB::table('sale_records')->where('id', $old->id)->update(['created_at' => now()->subDays(200)]);
        $unpaid = (string) Str::uuid();
        $this->push($t, [['table' => 'sales', 'uuid' => $unpaid, 'data' => ['occurred_at' => SyncSequence::nowMs(), 'customer_name' => 'Thembo', 'items' => [['product_uuid' => $p['uuid'], 'quantity' => 1]]]]]);
        DB::table('sale_records')->where('uuid', $unpaid)->update(['created_at' => now()->subDays(200)]);
        DB::table('stock_items')->where('id', $p['id'])->update(['created_at' => now()->subDays(400)]); // master data: always sent

        // New client: keyset with `after` + `upto`.
        $b1 = $this->postJson('/api/v1/sync/bootstrap', ['tables' => ['sales', 'products', 'tax_classes'], 'page_size' => 2], $t['h'])->assertOk()->json('data');
        $this->assertSame(90, $b1['history_days']);
        $this->assertCount(2, $b1['tables']['sales']['rows']);
        $this->assertTrue($b1['tables']['sales']['has_more']);
        $this->assertCount(1, $b1['tables']['products']['rows']);
        $this->assertArrayHasKey('cursor', $b1['tables']['tax_classes']);
        $b2 = $this->postJson('/api/v1/sync/bootstrap', ['tables' => ['sales'], 'page_size' => 2, 'after' => ['sales' => $b1['tables']['sales']['next_after']], 'upto' => $b1['seq']], $t['h'])->assertOk()->json('data');
        $this->assertCount(1, $b2['tables']['sales']['rows']);
        $this->assertFalse($b2['tables']['sales']['has_more']);
        $uuids = array_merge(array_column($b1['tables']['sales']['rows'], 'uuid'), array_column($b2['tables']['sales']['rows'], 'uuid'));
        $this->assertContains($unpaid, $uuids, 'an old sale still owed is kept');
        $this->assertNotContains($old->uuid, $uuids, 'an old paid sale is outside the window');

        // Older app: page numbers only; its next page continues where the last one stopped (remembered per device).
        $o1 = $this->postJson('/api/v1/sync/bootstrap', ['tables' => ['sales'], 'page' => 1, 'page_size' => 2, 'history_days' => 0], $t['h'])->assertOk()->json('data');
        $this->assertSame(2, $o1['tables']['sales']['next_page']);
        $o2 = $this->postJson('/api/v1/sync/bootstrap', ['tables' => ['sales'], 'page' => 2, 'page_size' => 2, 'history_days' => 0], $t['h'])->assertOk()->json('data');
        $this->assertSame($o1['seq'], $o2['seq']);
        $all = array_merge(array_column($o1['tables']['sales']['rows'], 'uuid'), array_column($o2['tables']['sales']['rows'], 'uuid'));
        $this->assertCount(4, array_unique($all), 'history_days=0: every sale, none twice');
    }

    public function test_cash_movements_push_and_pull(): void
    {
        $t = $this->tenant();
        $this->features($t, ['cash_control' => true]);
        $shift = (string) Str::uuid();
        $move = (string) Str::uuid();
        $r = $this->push($t, [
            ['table' => 'shifts', 'uuid' => $shift, 'data' => ['opening_float' => 10000]],
            ['table' => 'cash_movements', 'uuid' => $move, 'data' => ['shift_uuid' => $shift, 'type' => 'paid_in', 'amount' => 2000, 'reason' => 'Change from the bank']],
        ]);
        $this->assertSame('applied', $r['status']);
        $replay = $this->push($t, [['table' => 'cash_movements', 'uuid' => $move, 'data' => ['shift_uuid' => $shift, 'type' => 'paid_in', 'amount' => 2000, 'reason' => 'Change from the bank']]]);
        $this->assertSame('replayed', $replay['ops'][0]['status']);
        $rows = $this->getJson('/api/v1/sync/pull?table=cash_movements&since_seq=0', $t['h'])->assertOk()->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame($shift, $rows[0]['shift_uuid']);
        $this->assertGreaterThan(0, $rows[0]['server_seq']);
        $other = $this->tenant();
        $this->assertSame([], $this->getJson('/api/v1/sync/pull?table=cash_movements&since_seq=0', $other['h'])->json('data.rows'));

        $bad = $this->push($t, [['table' => 'cash_movements', 'uuid' => (string) Str::uuid(), 'data' => ['shift_uuid' => $shift, 'type' => 'paid_in', 'amount' => 5, 'reason' => 'x']]]);
        $this->assertSame('rejected', $bad['status']);
        $this->assertSame('reason_required', $bad['ops'][0]['code']);
    }

    public function test_product_and_customer_store_fields_sync_with_validation_and_loyalty_points(): void
    {
        $t = $this->tenant();
        $tax = DB::table('tax_classes')->insertGetId(['company_id' => $t['company_id'], 'name' => 'Std', 'code' => 'standard', 'rate' => 18, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $p = $this->product($t);
        $r = $this->push($t, [['table' => 'products', 'uuid' => $p['uuid'], 'action' => 'update', 'data' => ['plu_code' => '4011', 'sold_by' => 'weight', 'shelf_location' => 'A3', 'min_age' => 18, 'tax_class_id' => $tax, 'open_price' => 1]]]);
        $this->assertSame('applied', $r['status']);
        $row = DB::table('stock_items')->where('id', $p['id'])->first();
        $this->assertSame('4011', $row->plu_code);
        $this->assertSame('weight', $row->sold_by);
        $this->assertSame((int) $tax, (int) $row->tax_class_id);

        $other = $this->tenant();
        $foreignTax = DB::table('tax_classes')->insertGetId(['company_id' => $other['company_id'], 'name' => 'X', 'code' => 'standard', 'rate' => 5, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $bad = $this->push($t, [['table' => 'products', 'uuid' => $p['uuid'], 'action' => 'update', 'data' => ['tax_class_id' => $foreignTax]]]);
        $this->assertSame('rejected', $bad['status']);
        $this->assertSame('validation', $bad['ops'][0]['code']);

        $pulled = $this->getJson('/api/v1/sync/pull?table=products&since_seq=0', $t['h'])->json('data.rows.0');
        $this->assertSame('A3', $pulled['shelf_location']);
        $this->assertArrayHasKey('deposit_item_uuid', $pulled);

        $c = (string) Str::uuid();
        $this->push($t, [['table' => 'customers', 'uuid' => $c, 'data' => ['name' => 'Amina', 'phone' => '0772000111', 'price_level' => 'wholesale', 'marketing_opt_in' => 1]]]);
        $cid = DB::table('customers')->where('uuid', $c)->value('id');
        $this->assertNotNull(DB::table('customers')->where('id', $cid)->value('marketing_opt_in_at'), 'consent date stamped by the model');
        DB::table('loyalty_ledger')->insert(['company_id' => $t['company_id'], 'customer_id' => $cid, 'points' => 7, 'reason' => 'adjust', 'created_at' => now()]);
        $crow = collect($this->getJson('/api/v1/sync/pull?table=customers&since_seq=0', $t['h'])->json('data.rows'))->firstWhere('uuid', $c);
        $this->assertSame('wholesale', $crow['price_level']);
        $this->assertSame(7, $crow['loyalty_points']);
    }

    public function test_sale_op_new_fields_are_applied_and_old_ops_still_work(): void
    {
        $t = $this->tenant();
        $this->features($t, ['fast_tender' => true, 'promotions' => true, 'age_check' => true, 'loyalty' => true, 'gift_cards' => true], ['cash_rounding' => 50, 'loyalty_spend_per_point' => 1000]);
        $p = $this->product($t, 100, 1030);
        $promo = DB::table('promotions')->insertGetId(['company_id' => $t['company_id'], 'name' => 'Coupon 100 off', 'type' => 'amount_off', 'rules' => '{}', 'is_active' => 1, 'code' => 'SAVE']);
        $customer = (string) Str::uuid();
        $this->push($t, [['table' => 'customers', 'uuid' => $customer, 'data' => ['name' => 'Loyal', 'phone' => '0772555000']]]);

        // 2 × 1030 = 2060, promo 100 → 1960, rounding +40 → 2000, paid 1500 cash + 500 gift card.
        $card = (new GiftCardService())->sell($t['company_id'], $t['user_id'], 5000, 'cash', []);
        $sale = (string) Str::uuid();
        $r = $this->push($t, [
            ['table' => 'sales', 'uuid' => $sale, 'data' => ['occurred_at' => SyncSequence::nowMs(), 'has_payment_ops' => 1, 'customer_uuid' => $customer,
                'rounding' => 40, 'age_checked' => 1, 'coupon_code' => 'SAVE', 'price_level' => 'retail',
                'promotions' => [['promotion_id' => $promo, 'amount' => 100]],
                'items' => [['product_uuid' => $p['uuid'], 'quantity' => 2, 'unit_price' => 1030, 'promo_discount' => 100]]]],
            ['table' => 'payments', 'uuid' => (string) Str::uuid(), 'data' => ['sale_uuid' => $sale, 'method' => 'cash', 'amount' => 1500]],
            ['table' => 'payments', 'uuid' => (string) Str::uuid(), 'data' => ['sale_uuid' => $sale, 'method' => 'gift_card', 'code' => $card['code'], 'amount' => 500]],
        ]);
        $this->assertSame('applied', $r['status'], json_encode($r['ops']));
        $s = SaleRecord::where('uuid', $sale)->first();
        $this->assertEquals(2000, (float) $s->total_amount);
        $this->assertEquals(40, (float) $s->rounding_amount);
        $this->assertSame((int) $t['user_id'], (int) $s->age_checked_by);
        $this->assertEquals(0, (float) $s->balance);
        $this->assertEquals(100, (float) DB::table('sale_record_items')->where('sale_record_id', $s->id)->value('promo_discount'));
        $this->assertSame('Coupon 100 off', DB::table('sale_promotions')->where('sale_id', $s->id)->value('name'));
        $this->assertEquals(4500, (float) DB::table('gift_cards')->where('id', $card['card']->id)->value('balance'));
        // Points are earned once the payment ops are in: 2000 paid (cash + gift card; only points never earn) → 2 points.
        $this->assertSame(2, (int) DB::table('loyalty_ledger')->where('sale_record_id', $s->id)->where('reason', 'earn')->value('points'));

        // A sale whose rounding would make it negative is refused; a negative discount too.
        $neg = $this->push($t, [['table' => 'sales', 'uuid' => (string) Str::uuid(), 'data' => ['occurred_at' => SyncSequence::nowMs(), 'rounding' => -5000, 'items' => [['product_uuid' => $p['uuid'], 'quantity' => 1]]]]]);
        $this->assertSame('rejected', $neg['status']);
        $this->assertSame('rounding_mismatch', $neg['ops'][0]['code']);
        $neg2 = $this->push($t, [['table' => 'sales', 'uuid' => (string) Str::uuid(), 'data' => ['occurred_at' => SyncSequence::nowMs(), 'items' => [['product_uuid' => $p['uuid'], 'quantity' => 1, 'promo_discount' => -10]]]]]);
        $this->assertSame('validation', $neg2['ops'][0]['code']);

        // An old-style sale op (none of the new fields, amount_paid on the sale) still works as before.
        $oldUuid = (string) Str::uuid();
        $old = $this->push($t, [['table' => 'sales', 'uuid' => $oldUuid, 'data' => ['occurred_at' => SyncSequence::nowMs(), 'amount_paid' => 1030, 'payment_method' => 'cash', 'items' => [['product_uuid' => $p['uuid'], 'quantity' => 1]]]]]);
        $this->assertSame('applied', $old['status']);
        $o = SaleRecord::where('uuid', $oldUuid)->first();
        $this->assertEquals(1030, (float) $o->total_amount);
        $this->assertEquals(0, (float) $o->balance);
        $this->assertNull($o->rounding_amount);
    }
}

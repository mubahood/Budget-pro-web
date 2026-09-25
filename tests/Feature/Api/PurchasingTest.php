<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Plan;
use App\Models\StockItem;
use App\Models\Subscription;
use App\Services\Shop\ProductStatsService;
use Illuminate\Support\Facades\DB;

/** Plan A5/A7 (P4-1, P4-3): purchase orders, partial receipts with cost variances, returns to suppliers, reorder list. */
class PurchasingTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
    }

    private function product(string $name, array $o = []): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', array_merge(['name' => $name, 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => 10], $o), $this->h)
            ->assertStatus(201)->json('data');
    }

    private function qty(int $id): float
    {
        return (float) StockItem::withoutGlobalScopes()->find($id)->current_quantity;
    }

    public function test_purchase_order_lifecycle_with_partial_receipts_and_cost_variance(): void
    {
        $soda = $this->product('Soda');
        $bread = $this->product('Bread', ['buying_price' => 3500]);
        $sup = $this->postJson('/api/v1/suppliers', ['name' => 'Kampala Wholesalers', 'phone' => '0772 111 222'], $this->h)->json('data.id');

        $po = $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $sup, 'expected_date' => now()->addDays(3)->toDateString(), 'items' => [
            ['stock_item_id' => $soda['id'], 'quantity' => 48],
            ['stock_item_id' => $bread['id'], 'quantity' => 10, 'unit_cost' => 3400],
        ]], $this->h)->assertStatus(201)->json('data');
        $this->assertSame('draft', $po['status']);
        $this->assertStringStartsWith('PO-', $po['number']);
        $this->assertEquals(48 * 600 + 10 * 3400, (float) $po['subtotal']);
        $this->assertEquals(10, $this->qty($soda['id']), 'a draft moves no stock');
        $this->postJson('/api/v1/purchase-orders', ['items' => [['stock_item_id' => $soda['id'], 'quantity' => 1], ['stock_item_id' => $soda['id'], 'quantity' => 2]]], $this->h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'duplicate_line');

        $sent = $this->postJson("/api/v1/purchase-orders/{$po['id']}/send", [], $this->h)->assertOk()->json('data');
        $this->assertStringContainsString('48 × Soda', $sent['text']);
        $this->assertStringStartsWith('https://wa.me/256772111222?text=', $sent['whatsapp_url']);
        $this->putJson("/api/v1/purchase-orders/{$po['id']}", ['items' => [['stock_item_id' => $soda['id'], 'quantity' => 1]]], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'po_not_draft');

        $lines = collect($po['items'])->keyBy('stock_item_id');
        // First delivery: 24 sodas at a higher cost, no bread yet.
        $r = $this->postJson("/api/v1/purchase-orders/{$po['id']}/receive", ['items' => [
            ['purchase_order_item_id' => $lines[$soda['id']]['id'], 'quantity' => 24, 'unit_cost' => 650],
            ['purchase_order_item_id' => $lines[$bread['id']]['id'], 'quantity' => 0],
        ], 'amount_paid' => 5000], $this->h)->assertStatus(201)->json('data');
        $this->assertSame('partially_received', $r['order']['status']);
        $this->assertEquals(34, $this->qty($soda['id']));
        $this->assertEquals(24 * 50, $r['order']['progress']['cost_variance']);
        $this->assertSame($po['id'], $r['goods_receipt']['purchase_order_id']);
        $this->assertEquals(650, (float) StockItem::withoutGlobalScopes()->find($soda['id'])->buying_price, 'cost follows the latest delivery');

        // Second delivery completes the order.
        $r = $this->postJson("/api/v1/purchase-orders/{$po['id']}/receive", ['items' => [
            ['purchase_order_item_id' => $lines[$soda['id']]['id'], 'quantity' => 24],
            ['purchase_order_item_id' => $lines[$bread['id']]['id'], 'quantity' => 10],
        ]], $this->h)->assertStatus(201)->json('data');
        $this->assertSame('received', $r['order']['status']);
        $this->assertCount(2, $r['order']['progress']['receipts']);
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/receive", ['items' => [['purchase_order_item_id' => $lines[$soda['id']]['id'], 'quantity' => 1]]], $this->h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'po_closed');

        // Supplier owes: 24×650 + 24×600 + 10×3400 − 5000 paid.
        $this->assertEquals(24 * 650 + 24 * 600 + 10 * 3400 - 5000, (float) DB::table('suppliers')->where('id', $sup)->value('balance'));

        // A partially delivered order can be closed; a draft can be deleted; a closed one cannot be cancelled.
        $po2 = $this->postJson('/api/v1/purchase-orders', ['items' => [['stock_item_id' => $soda['id'], 'quantity' => 10]]], $this->h)->json('data');
        $this->deleteJson("/api/v1/purchase-orders/{$po2['id']}", [], $this->h)->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/cancel", [], $this->h)->assertStatus(422);
    }

    public function test_return_to_supplier_moves_stock_out_lowers_what_we_owe_and_records_the_refund(): void
    {
        $milk = $this->product('Milk');
        $sup = $this->postJson('/api/v1/suppliers', ['name' => 'Dairy Co'], $this->h)->json('data.id');
        $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $sup, 'items' => [['stock_item_id' => $milk['id'], 'quantity' => 20, 'unit_cost' => 1000]]], $this->h)->assertStatus(201);
        $this->assertEquals(20000, (float) DB::table('suppliers')->where('id', $sup)->value('balance'));

        $ret = $this->postJson('/api/v1/purchase-returns', ['supplier_id' => $sup, 'reason' => 'Sour on arrival', 'refund_amount' => 2000, 'items' => [
            ['stock_item_id' => $milk['id'], 'quantity' => 5, 'unit_cost' => 1000],
        ]], $this->h)->assertStatus(201)->json('data');
        $this->assertStringStartsWith('PRT-', $ret['number']);
        $this->assertEquals(25, $this->qty($milk['id']));
        $this->assertSame('Purchase Return', DB::table('stock_records')->where('reference_type', 'purchase_return')->value('type'));
        // 20,000 owed − 5,000 returned + 2,000 refunded in cash = 17,000.
        $this->assertEquals(17000, (float) DB::table('suppliers')->where('id', $sup)->value('balance'));
        $this->assertTrue(DB::table('financial_records')->where('source_type', 'purchase_return')->where('type', 'Income')->where('amount', 2000)->exists());

        $st = $this->getJson("/api/v1/suppliers/{$sup}/statement", $this->h)->assertOk()->json('data');
        $this->assertEquals(17000, $st['closing_balance']);
        $this->assertContains('return', array_column($st['entries'], 'type'));
        $this->putJson("/api/v1/purchase-returns/{$ret['id']}", [], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'immutable_document');
    }

    public function test_reorder_list_uses_sales_velocity_and_min_stock_and_becomes_orders_per_supplier(): void
    {
        $fast = $this->product('Sugar', ['original_quantity' => 70, 'min_stock' => 10]);
        $slow = $this->product('Candles', ['original_quantity' => 3, 'min_stock' => 5]);
        $fine = $this->product('Salt', ['original_quantity' => 100, 'min_stock' => 5]);
        $supA = $this->postJson('/api/v1/suppliers', ['name' => 'A', 'lead_time_days' => 10], $this->h)->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $supA, 'items' => [['stock_item_id' => $fast['id'], 'quantity' => 1, 'unit_cost' => 4400]]], $this->h)->assertStatus(201);
        // 63 sugar sold over the last 30 days (≈2.1/day) leaves 8 on hand.
        $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 63000]], 'items' => [['stock_item_id' => $fast['id'], 'quantity' => 63]]], $this->h)->assertStatus(201);
        DB::table('stock_records')->where('stock_item_id', $fast['id'])->where('type', 'Sale')->update(['date' => now()->subDays(10)]);

        $this->assertGreaterThanOrEqual(3, app(ProductStatsService::class)->refresh((int) $this->t['company_id']));
        $stats = DB::table('product_stats')->where('stock_item_id', $fast['id'])->first();
        $this->assertEquals(63, (float) $stats->sold_30);
        $this->assertEquals(0, (float) $stats->sold_7);
        $this->assertEquals(2.1, (float) $stats->avg_daily_30);

        $list = collect($this->getJson('/api/v1/reorder-suggestions', $this->h)->assertOk()->json('data'))->keyBy('stock_item_id');
        $this->assertFalse($list->has($fine['id']));
        $this->assertSame(21, $list[$fast['id']]['suggested_quantity'], '2.1/day × 10 days lead time beats 2×10−8');
        $this->assertSame($supA, $list[$fast['id']]['supplier_id']);
        $this->assertSame(7, $list[$slow['id']]['suggested_quantity'], 'no sales: back to twice the minimum (2×5−3)');
        $this->assertNull($list[$fast['id']]['runs_out_in_days'], 'stock-out forecast is a plan feature');

        $plan = Plan::create(['name' => 'Business', 'slug' => 'biz-'.uniqid(), 'price' => 49, 'price_ugx' => 185000, 'currency' => 'UGX', 'interval' => 'month', 'trial_days' => 0,
            'is_active' => true, 'is_public' => true, 'sort_order' => 3, 'features' => ['forecasting' => true], 'limits' => []]);
        Subscription::create(['company_id' => $this->t['company_id'], 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        Company::withoutGlobalScopes()->find($this->t['company_id'])->unsetRelation('subscription');
        $list = collect($this->getJson('/api/v1/reorder-suggestions', $this->h)->json('data'))->keyBy('stock_item_id');
        $this->assertSame(3, $list[$fast['id']]['runs_out_in_days']);

        $orders = $this->postJson('/api/v1/reorder-suggestions/orders', ['items' => [
            ['stock_item_id' => $fast['id'], 'quantity' => 21, 'supplier_id' => $supA],
            ['stock_item_id' => $slow['id'], 'quantity' => 7],
        ]], $this->h)->assertStatus(201)->json('data');
        $this->assertCount(2, $orders, 'one draft per supplier (and one without)');
        $this->assertSame('draft', $orders[0]['status']);
    }

    public function test_cashier_cannot_order_or_return(): void
    {
        $link = $this->postJson('/api/v1/team/invites', ['role' => 'cashier', 'phone' => '0772919001'], $this->h)->json('data.link');
        $token = $this->postJson('/api/v1/invites/'.basename($link).'/accept', ['first_name' => 'C', 'last_name' => 'A', 'password' => 'secret123'])->json('data.token');
        $ch = $this->auth($token);
        $p = $this->product('Tea');
        $this->postJson('/api/v1/purchase-orders', ['items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $ch)->assertStatus(403)->assertJsonPath('errors.permission', 'restock');
        $this->postJson('/api/v1/purchase-returns', ['items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $ch)->assertStatus(403);
        $this->getJson('/api/v1/reorder-suggestions', $ch)->assertStatus(403);
        $this->getJson('/api/v1/purchase-orders', $ch)->assertOk();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Models\StockSubCategory;
use App\Models\StockTake;
use App\Models\Subscription;
use App\Services\Shop\SaleService;
use App\Services\Shop\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Stock and money screens of the web admin (review 2026-09-30). */
class StockScreensTest extends AdminTestCase
{
    /** @return array{user: \App\Models\User, company: \App\Models\Company, period: FinancialPeriod, sub: StockSubCategory, product: StockItem} */
    private function shop(string $productName = 'Rice'): array
    {
        $t = $this->makeTenant('company');
        $cid = $t['company']->id;
        $t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()], 'timezone' => 'Africa/Kampala'])->saveQuietly();
        Subscription::create(['company_id' => $cid, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        Auth::guard('admin')->login($t['user']);
        $period = FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Food']);
        $sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Dry '.$productName, 'measurement_unit' => 'kg']);
        $p = StockItem::create(['company_id' => $cid, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => $productName, 'sku' => 'S-'.uniqid(), 'buying_price' => 4000, 'selling_price' => 5000, 'original_quantity' => 20]);

        return $t + ['period' => $period, 'sub' => $sub, 'product' => $p];
    }

    public function test_pick_lists_only_show_the_signed_in_shop(): void
    {
        $b = $this->shop('Rice B');
        $a = $this->shop('Rice A');
        $deleted = StockItem::withoutGlobalScopes()->find($a['product']->id)->replicate();
        $deleted->forceFill(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Rice gone', 'sku' => 'G-'.uniqid(), 'current_quantity' => 0, 'original_quantity' => 0])->saveQuietly();
        DB::table('stock_items')->where('id', $deleted->id)->update(['is_deleted' => 1]);

        $items = $this->asAdmin($a['user'])->getJson('/ajax/stock-items?q=Rice&company_id='.$b['company']->id)->assertOk()->json('data');
        $this->assertSame([$a['product']->id], array_column($items, 'id'), 'own, live products only — company_id in the URL is ignored');
        $this->assertStringContainsString('in stock: 20', $items[0]['text']);
        $this->assertSame([$a['product']->id], array_column($this->asAdmin($a['user'])->getJson('/ajax/stock-items?q='.urlencode($a['product']->sku))->json('data'), 'id'), 'search by SKU');

        $subs = $this->asAdmin($a['user'])->getJson('/ajax/sub-categories?company_id='.$b['company']->id)->assertOk()->json('data');
        $this->assertSame([$a['sub']->id], array_column($subs, 'id'));
        $this->assertSame('Dry Rice A - Food (kg)', $subs[0]['text']);

        $this->asAdmin($a['user'])->postJson('/ajax/categories', ['name' => 'Drinks'])->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame(1, StockCategory::withoutGlobalScopes()->where('company_id', $a['company']->id)->where('name', 'Drinks')->count());
        $parent = StockCategory::withoutGlobalScopes()->where('company_id', $b['company']->id)->first();
        $this->asAdmin($a['user'])->postJson('/ajax/categories', ['name' => 'Sodas', 'parent_id' => $parent->id])->assertStatus(422); // not their category

        // The product and movement forms point at these endpoints.
        $this->asAdmin($a['user'])->get('/stock-items/create')->assertOk()->assertSee('ajax/sub-categories', false);
        $this->asAdmin($a['user'])->get('/stock-records/create')->assertOk()->assertSee('ajax/stock-items', false);
    }

    public function test_the_movement_form_cannot_make_a_sale_and_blank_cost_is_not_zero(): void
    {
        $s = $this->shop();
        $p = $s['product'];

        $this->asAdmin($s['user'])->get('/stock-records/create?stock_item_id='.$p->id.'&type=Damage')->assertOk()->assertDontSee('Sale (stock out, revenue)');
        $this->asAdmin($s['user'])->post('/stock-records', ['stock_item_id' => $p->id, 'type' => 'Sale', 'quantity' => 2, 'selling_price' => '']);
        $this->assertSame(0, StockRecord::withoutGlobalScopes()->where('stock_item_id', $p->id)->where('type', 'Sale')->count(), 'no Sale movement from this form');
        $this->assertEquals(20, (float) $p->fresh()->current_quantity);
        $this->assertSame(0, DB::table('financial_records')->where('company_id', $s['company']->id)->count(), 'no zero-price income row');

        $this->asAdmin($s['user'])->post('/stock-records', ['stock_item_id' => $p->id, 'type' => 'Stock In', 'quantity' => 5, 'unit_cost' => '', 'reason' => 'restock']);
        $in = StockRecord::withoutGlobalScopes()->where('stock_item_id', $p->id)->where('type', 'Stock In')->firstOrFail();
        $this->assertEquals(4000, (float) $in->unit_cost, 'blank cost uses the product cost, not 0');
        $this->assertEquals(25, (float) $p->fresh()->current_quantity);

        $this->asAdmin($s['user'])->post('/stock-records', ['stock_item_id' => $p->id, 'type' => 'Damage', 'quantity' => 1, 'unit_cost' => '999', 'reason' => 'damage']);
        $damage = StockRecord::withoutGlobalScopes()->where('stock_item_id', $p->id)->where('type', 'Damage')->firstOrFail();
        $this->assertEquals(0, (float) $damage->total_sales);
        $this->assertEquals(4000, (float) $damage->unit_cost, 'cost only applies to stock coming in');
        $this->asAdmin($s['user'])->get('/stock-records?_search_=Rice')->assertOk()->assertSee('Rice');
    }

    public function test_movements_of_a_document_cannot_be_reversed_but_stand_alone_ones_can(): void
    {
        $s = $this->shop();
        $p = $s['product'];
        $sale = (new SaleService())->checkout($s['company']->id, $s['user']->id, ['payments' => [['method' => 'cash', 'amount' => 10000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $p->id, 'quantity' => 2]]])['sale'];
        $saleMove = StockRecord::withoutGlobalScopes()->where('sale_record_id', $sale->id)->firstOrFail();

        $this->asAdmin($s['user'])->post("/stock-records/{$saleMove->id}/reverse")->assertRedirect(admin_url('stock-records/'.$saleMove->id));
        $this->assertFalse(StockRecord::withoutGlobalScopes()->where('reverses_id', $saleMove->id)->exists());
        $this->assertEquals(18, (float) $p->fresh()->current_quantity);
        $this->asAdmin($s['user'])->get('/stock-records/'.$saleMove->id)->assertOk()->assertSee('void the sale', false)->assertDontSee('Undo this movement');

        $grn = (new \App\Services\Shop\GoodsReceiptService())->receive($s['company']->id, $s['user']->id, [['stock_item_id' => $p->id, 'quantity' => 5, 'unit_cost' => 4000]]);
        $grnMove = StockRecord::withoutGlobalScopes()->where('reference_type', 'goods_receipt')->where('reference_id', $grn->id)->firstOrFail();
        $this->asAdmin($s['user'])->post("/stock-records/{$grnMove->id}/reverse")->assertRedirect();
        $this->assertFalse(StockRecord::withoutGlobalScopes()->where('reverses_id', $grnMove->id)->exists());

        $damage = (new StockService())->record(['stock_item_id' => $p->id, 'type' => 'Damage', 'quantity' => 1, 'created_by_id' => $s['user']->id]);
        $this->asAdmin($s['user'])->get('/stock-records/'.$damage->id)->assertOk()->assertSee('Undo this movement');
        $this->asAdmin($s['user'])->post("/stock-records/{$damage->id}/reverse", ['reason' => 'typo'])->assertRedirect();
        $this->assertTrue(StockRecord::withoutGlobalScopes()->where('reverses_id', $damage->id)->exists());
        $this->assertEquals(23, (float) $p->fresh()->current_quantity);
    }

    public function test_goods_received_without_a_cost_keep_the_product_cost(): void
    {
        $s = $this->shop();
        $p = $s['product'];
        $this->asAdmin($s['user'])->post('/goods-receipts', ['amount_paid' => 0, 'items' => [['stock_item_id' => $p->id, 'quantity' => 10, 'unit_cost' => '', '_remove_' => 0]]])->assertRedirect();
        $this->assertEquals(30, (float) $p->fresh()->current_quantity);
        $this->assertEquals(4000, (float) $p->fresh()->buying_price, 'blank cost never wipes the buying price');
        $this->assertEquals(4000, (float) DB::table('goods_receipt_items')->where('stock_item_id', $p->id)->value('unit_cost'));
        $this->assertEquals(40000, (float) DB::table('goods_receipts')->where('company_id', $s['company']->id)->value('total_cost'));

        (new \App\Services\Shop\GoodsReceiptService())->receive($s['company']->id, $s['user']->id, [['stock_item_id' => $p->id, 'quantity' => 1, 'unit_cost' => 0]]);
        $this->assertEquals(4000, (float) $p->fresh()->buying_price, 'free goods do not set the cost to 0');
        (new \App\Services\Shop\GoodsReceiptService())->receive($s['company']->id, $s['user']->id, [['stock_item_id' => $p->id, 'quantity' => 1, 'unit_cost' => 4200]]);
        $this->assertEquals(4200, (float) $p->fresh()->buying_price, 'a real new cost is still followed');
        $this->asAdmin($s['user'])->get('/goods-receipts/create')->assertOk()->assertSee('bpCosts', false);
    }

    public function test_a_stock_count_keeps_sales_made_after_counting(): void
    {
        $s = $this->shop();
        $p = $s['product'];
        $this->asAdmin($s['user'])->get('/stock-takes/create')->assertRedirect();
        $this->asAdmin($s['user'])->get('/stock-takes/create')->assertRedirect();
        $this->assertSame(1, StockTake::withoutGlobalScopes()->where('company_id', $s['company']->id)->count(), 'refreshing reuses the open count');
        $take = StockTake::withoutGlobalScopes()->where('company_id', $s['company']->id)->firstOrFail();

        // Counted 18 on the shelf while the system showed 20 (2 missing)…
        $this->asAdmin($s['user'])->post("/stock-takes/{$take->id}/count", ['counts' => [$p->id => 18]])->assertRedirect();
        $this->asAdmin($s['user'])->get("/stock-takes/{$take->id}/count")->assertOk()->assertSee('value="18"', false);
        // …then 3 were sold before the count was posted.
        (new SaleService())->checkout($s['company']->id, $s['user']->id, ['payments' => [['method' => 'cash', 'amount' => 15000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $p->id, 'quantity' => 3]]]);
        // Re-sending the same count (the form posts every product again) keeps the snapshot.
        $this->asAdmin($s['user'])->post("/stock-takes/{$take->id}/count", ['counts' => [$p->id => 18]])->assertRedirect();
        $this->asAdmin($s['user'])->post("/stock-takes/{$take->id}/post")->assertRedirect();

        $this->assertEquals(15, (float) $p->fresh()->current_quantity, '20 − 2 missing − 3 sold; the sale is not wiped');
        $this->assertEquals(-2, (float) DB::table('stock_take_items')->where('stock_take_id', $take->id)->value('delta'));

        $this->asAdmin($s['user'])->post('/stock-takes')->assertRedirect();
        $this->assertSame(2, StockTake::withoutGlobalScopes()->where('company_id', $s['company']->id)->count(), 'POST starts a new count');
    }

    public function test_sub_category_stock_counts_products_from_every_period(): void
    {
        $s = $this->shop();
        DB::table('financial_periods')->where('id', $s['period']->id)->update(['status' => 'Closed']);
        FinancialPeriod::withoutGlobalScopes()->create(['company_id' => $s['company']->id, 'status' => 'Active', 'name' => 'FY2', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);

        $sub = StockSubCategory::withoutGlobalScopes()->find($s['sub']->id);
        $sub->update_self();
        $this->assertEquals(20, (float) $sub->fresh()->current_quantity, 'a new period does not empty the shelf');
        $this->assertSame('Yes', $sub->fresh()->in_stock);
    }

    public function test_global_search_finds_a_sale_by_receipt_number_in_the_own_shop_only(): void
    {
        $b = $this->shop('Beans');
        $saleB = (new SaleService())->checkout($b['company']->id, $b['user']->id, ['customer_name' => 'Other Shop Buyer', 'payments' => [['method' => 'cash', 'amount' => 5000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $b['product']->id, 'quantity' => 1]]])['sale'];
        $a = $this->shop();
        $saleA = (new SaleService())->checkout($a['company']->id, $a['user']->id, ['customer_name' => 'Nakato Amina', 'payments' => [['method' => 'cash', 'amount' => 5000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $a['product']->id, 'quantity' => 1]]])['sale'];
        $this->assertNotEmpty($saleA->receipt_number);

        $r = $this->asAdmin($a['user'])->getJson('/api/global-search?q='.urlencode($saleA->receipt_number))->assertOk();
        $ids = array_column($r->json('sales'), 'id');
        $this->assertContains($saleA->id, $ids);
        $this->assertNotContains($saleB->id, $ids);
        $this->assertStringEndsWith('sale-records/'.$saleA->id, $r->json('sales.0.url'));

        $this->assertSame([], $this->asAdmin($a['user'])->getJson('/api/global-search?q=Other%20Shop')->json('sales'));
        $this->assertSame([$saleA->id], array_column($this->asAdmin($a['user'])->getJson('/api/global-search?q=Nakato')->json('sales'), 'id'));
        $products = $this->asAdmin($a['user'])->getJson('/api/global-search?q=Beans')->json('products');
        $this->assertSame([], $products, 'the other shop\'s products never show');
    }

    public function test_system_posted_money_rows_cannot_be_edited_and_totals_are_separate(): void
    {
        $s = $this->shop();
        (new SaleService())->checkout($s['company']->id, $s['user']->id, ['payments' => [['method' => 'cash', 'amount' => 5000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $s['product']->id, 'quantity' => 1]]]);
        $row = \App\Models\FinancialRecord::withoutGlobalScopes()->where('company_id', $s['company']->id)->whereNotNull('source_type')->firstOrFail();

        $this->asAdmin($s['user'])->get("/financial-records/{$row->id}/edit")->assertRedirect(admin_url('financial-records/'.$row->id));
        $this->asAdmin($s['user'])->put("/financial-records/{$row->id}", ['amount' => 1, 'type' => 'Expense'])->assertRedirect(admin_url('financial-records/'.$row->id));
        $this->assertEquals(5000, (float) $row->fresh()->amount);
        $this->asAdmin($s['user'])->delete("/financial-records/{$row->id}")->assertJson(['status' => false]);
        $this->assertNotNull($row->fresh());
        $this->asAdmin($s['user'])->get("/financial-records/{$row->id}")->assertOk()->assertSee('Posted automatically from');

        $html = $this->asAdmin($s['user'])->get('/financial-records')->assertOk()->getContent();
        $this->assertStringContainsString('Income:', $html);
        $this->assertStringContainsString('Net:', $html);
        $this->asAdmin($s['user'])->get('/financial-records/create')->assertOk()
            ->assertSee(now()->setTimezone('Africa/Kampala')->toDateString());
    }

    public function test_product_list_scopes_and_customers_who_owe(): void
    {
        $s = $this->shop();
        $cid = $s['company']->id;
        $free = StockItem::create(['company_id' => $cid, 'created_by_id' => $s['user']->id, 'stock_category_id' => $s['product']->stock_category_id, 'stock_sub_category_id' => $s['sub']->id,
            'name' => 'Sample sachet', 'sku' => 'F-'.uniqid(), 'buying_price' => 0, 'selling_price' => 500, 'original_quantity' => 3]);

        $this->asAdmin($s['user'])->get('/stock-items?_scope_=no_cost')->assertOk()->assertSee('Sample sachet')->assertDontSee('>Rice<', false);
        $this->asAdmin($s['user'])->get('/stock-items?_scope_=low')->assertOk()->assertSee('Sample sachet');
        $this->asAdmin($s['user'])->get('/stock-items?_scope_=out')->assertOk()->assertDontSee('Sample sachet');
        DB::table('stock_items')->where('id', $free->id)->update(['is_deleted' => 1]);
        $this->asAdmin($s['user'])->get('/stock-items')->assertOk()->assertDontSee('Sample sachet');

        $owes = Customer::create(['company_id' => $cid, 'name' => 'Owing Okello', 'phone' => '0700000001']);
        Customer::create(['company_id' => $cid, 'name' => 'Paid Up Pauline', 'phone' => '0700000002']);
        DB::table('customers')->where('id', $owes->id)->update(['balance' => 12000]);
        $this->asAdmin($s['user'])->get('/customers?owes=1')->assertOk()->assertSee('Owing Okello')->assertDontSee('Paid Up Pauline')
            ->assertSee('customers/'.$owes->id.'/pay', false)->assertSee('customers/'.$owes->id.'/remind', false);
    }

    public function test_quick_sale_goes_through_checkout(): void
    {
        $s = $this->shop();
        $r = $this->asAdmin($s['user'])->postJson('/api/sales/quick-record', ['stock_item_id' => $s['product']->id, 'quantity' => 2])->assertOk()->assertJson(['success' => true]);
        $sale = DB::table('sale_records')->where('company_id', $s['company']->id)->first();
        $this->assertNotNull($sale);
        $this->assertSame($sale->receipt_number, $r->json('data.receipt_number'));
        $this->assertEquals(10000, (float) $sale->total_amount);
        $this->assertEquals(18, (float) $s['product']->fresh()->current_quantity);

        $this->asAdmin($s['user'])->postJson('/api/sales/quick-record', ['stock_item_id' => $s['product']->id, 'quantity' => 500])->assertStatus(422);
        $this->assertEquals(18, (float) $s['product']->fresh()->current_quantity, 'stock rules apply');
    }
}

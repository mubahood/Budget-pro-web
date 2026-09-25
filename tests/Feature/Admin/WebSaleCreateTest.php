<?php

namespace Tests\Feature\Admin;

use App\Models\FinancialPeriod;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Subscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Client report (2026-09-25): selling on the web said "No items" although an item was picked. */
class WebSaleCreateTest extends AdminTestCase
{
    public function test_a_sale_made_on_the_web_form_records_items_stock_and_profit(): void
    {
        $t = $this->makeTenant('company');
        $t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()]])->saveQuietly();
        Subscription::create(['company_id' => $t['company']->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        Auth::guard('admin')->login($t['user']);
        $period = FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $t['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $t['company']->id, 'name' => 'Food']);
        $sub = StockSubCategory::create(['company_id' => $t['company']->id, 'stock_category_id' => $cat->id, 'name' => 'Dry', 'measurement_unit' => 'kg']);
        $p = StockItem::create(['company_id' => $t['company']->id, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Rice', 'sku' => 'R-'.uniqid(), 'buying_price' => 4000, 'selling_price' => 5000, 'original_quantity' => 20]);

        $this->asAdmin($t['user'])->get('/sale-records/create')->assertOk();

        $r = $this->asAdmin($t['user'])->post('/sale-records', [
            'financial_period_id' => $period->id, 'sale_date' => now()->toDateString(), 'customer_name' => 'Eng ladin',
            'saleRecordItems' => ['new_1' => ['stock_item_id' => $p->id, 'quantity' => 2, 'unit_price' => '', 'id' => '', '_remove_' => 0]],
            'payment_method' => 'Cash', 'amount_paid' => 10000, 'status' => 'Completed',
        ]);
        $sale = DB::table('sale_records')->where('company_id', $t['company']->id)->first();
        $this->assertNotNull($sale, 'sale saved');
        $this->assertEquals(18, (float) $p->fresh()->current_quantity);
        $this->assertSame('Completed', $sale->status);
        $this->assertEquals(2000, (float) DB::table('sale_record_items')->where('sale_record_id', $sale->id)->value('profit'));

        // Damaged goods (web: Stock movements → Damage) leave stock; a customer return brings it back.
        foreach ([['Damage', 3, 15], ['Return', 1, 16]] as [$type, $qty, $expected]) {
            $this->asAdmin($t['user'])->post('/stock-records', ['stock_item_id' => $p->id, 'type' => $type, 'quantity' => $qty, 'reason' => $type === 'Damage' ? 'damage' : 'return', 'description' => 'test']);
            $this->assertEquals($expected, (float) $p->fresh()->current_quantity, $type);
        }
        $this->assertSame(1, DB::table('stock_records')->where('stock_item_id', $p->id)->where('type', 'Damage')->count());

        // A customer brings goods back from the sale page: one good (restocked), one faulty (not restocked).
        $itemId = DB::table('sale_record_items')->where('sale_record_id', $sale->id)->value('id');
        $this->asAdmin($t['user'])->get('/sale-records/'.$sale->id)->assertOk()->assertSee('Record return');
        $this->asAdmin($t['user'])->post('/sale-records/'.$sale->id.'/return', ['lines' => [$itemId => ['quantity' => 1, 'condition' => 'good']], 'reason' => 'Changed mind'])->assertRedirect();
        $this->asAdmin($t['user'])->post('/sale-records/'.$sale->id.'/return', ['lines' => [$itemId => ['quantity' => 1, 'condition' => 'faulty']], 'reason' => 'Faulty'])->assertRedirect();
        $this->assertEquals(17, (float) $p->fresh()->current_quantity, 'only the good one went back on the shelf');
        $this->assertEquals(0, (float) DB::table('sale_record_items')->where('id', $itemId)->value('profit'), 'profit falls with the returned goods');
        $this->assertEquals(10000, (float) DB::table('sale_records')->where('id', $sale->id)->value('refunded_amount'));

        // The returns report shows both, the faulty one too, plus the stand-alone Return movement.
        \App\Support\LocalTime::prime($t['company']->id);
        $w = new \App\Admin\Widgets\ReturnsReportWidget();
        $data = (fn () => ['s' => $this->getReturnsSummary($t['company']->id), 'r' => $this->getReturnsByReason($t['company']->id), 'top' => $this->getTopReturnedProducts($t['company']->id)])->call($w);
        $this->assertSame(3, (int) $data['s']['today']->returns_count);
        $this->assertEquals(3, (float) $data['s']['total']->units_returned);
        $this->assertEquals(10000 + 5000, (float) $data['s']['total']->refund_total, 'the stand-alone Return movement is valued at its selling price');
        $this->assertContains('Faulty', array_column($data['r'], 'reason'));
        $this->assertSame('Rice', $data['top'][0]->name);
        $this->asAdmin($t['user'])->get('/')->assertOk();
    }

    public function test_old_write_offs_that_never_reduced_stock_can_be_applied(): void
    {
        $t = $this->makeTenant('company');
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $t['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $t['company']->id, 'name' => 'Food']);
        $sub = StockSubCategory::create(['company_id' => $t['company']->id, 'stock_category_id' => $cat->id, 'name' => 'Dry', 'measurement_unit' => 'kg']);
        $p = StockItem::create(['company_id' => $t['company']->id, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Beans', 'sku' => 'B-'.uniqid(), 'buying_price' => 4000, 'selling_price' => 5000, 'original_quantity' => 10]);
        $old = (new \App\Services\Shop\StockService())->record(['stock_item_id' => $p->id, 'type' => 'Damage', 'quantity' => 4, 'created_by_id' => $t['user']->id]);
        // What the Phase 0 backfill left behind: a Damage row that moved nothing.
        DB::table('stock_records')->where('id', $old->id)->update(['quantity_delta' => 0]);
        DB::table('stock_items')->where('id', $p->id)->update(['current_quantity' => 10]);
        DB::table('stock_levels')->where('stock_item_id', $p->id)->update(['quantity' => 10]);

        $this->artisan('stock:apply-old-writeoffs', ['--company' => $t['company']->id])->assertSuccessful();
        $this->assertEquals(10, (float) $p->fresh()->current_quantity, 'dry run changes nothing');

        $this->artisan('stock:apply-old-writeoffs', ['--company' => $t['company']->id, '--apply' => true])->assertSuccessful();
        $this->assertEquals(6, (float) $p->fresh()->current_quantity);
        $this->artisan('stock:apply-old-writeoffs', ['--company' => $t['company']->id, '--apply' => true])->assertSuccessful();
        $this->assertEquals(6, (float) $p->fresh()->current_quantity, 'a second run changes nothing');
        $this->assertSame(1, DB::table('stock_records')->where('reference_type', 'old_writeoff')->where('reference_id', $old->id)->count());
    }

    public function test_dashboard_counts_old_app_sales_and_excludes_voids(): void
    {
        $t = $this->makeTenant('company');
        $t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()], 'timezone' => 'Africa/Kampala'])->saveQuietly();
        Subscription::create(['company_id' => $t['company']->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        Auth::guard('admin')->login($t['user']);
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $t['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $t['company']->id, 'name' => 'Elec']);
        $sub = StockSubCategory::create(['company_id' => $t['company']->id, 'stock_category_id' => $cat->id, 'name' => 'Bulbs', 'measurement_unit' => 'pcs']);
        $p = StockItem::create(['company_id' => $t['company']->id, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Bulb', 'sku' => 'B-'.uniqid(), 'buying_price' => 600, 'selling_price' => 1000, 'original_quantity' => 50]);
        // The old app records a sale as a bare Sale movement (no sale document).
        (new \App\Services\Shop\StockService())->record(['stock_item_id' => $p->id, 'type' => 'Sale', 'quantity' => 3, 'selling_price' => 1000, 'created_by_id' => $t['user']->id]);
        // A web/API sale, and a voided one.
        $svc = new \App\Services\Shop\SaleService();
        $svc->checkout($t['company']->id, $t['user']->id, ['payments' => [['method' => 'cash', 'amount' => 2000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $p->id, 'quantity' => 2]]]);
        $void = $svc->checkout($t['company']->id, $t['user']->id, ['payments' => [['method' => 'cash', 'amount' => 5000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $p->id, 'quantity' => 5]]])['sale'];
        $svc->void($void, 'mistake', $t['user']->id);

        \App\Support\LocalTime::prime($t['company']->id);
        [$from, $bind] = \App\Support\SalesSource::sql($t['company']->id);
        $today = DB::selectOne("SELECT COUNT(*) n, SUM(total_amount) t, SUM(profit) p FROM {$from} WHERE sale_date = @local_today", $bind);
        $this->assertSame(2, (int) $today->n, 'old-app sale + web sale; the void is out');
        $this->assertEquals(5000, (float) $today->t);
        $this->assertEquals(2000, (float) $today->p);
        $this->asAdmin($t['user'])->get('/')->assertOk()->assertSee('5,000');
    }

    public function test_voiding_after_a_faulty_return_does_not_put_the_faulty_item_back(): void
    {
        $t = $this->makeTenant('company');
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $t['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $t['company']->id, 'name' => 'Elec']);
        $sub = StockSubCategory::create(['company_id' => $t['company']->id, 'stock_category_id' => $cat->id, 'name' => 'Irons', 'measurement_unit' => 'pcs']);
        $p = StockItem::create(['company_id' => $t['company']->id, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Iron box', 'sku' => 'I-'.uniqid(), 'buying_price' => 30000, 'selling_price' => 45000, 'original_quantity' => 10]);
        $sales = new \App\Services\Shop\SaleService();
        $sale = $sales->checkout($t['company']->id, $t['user']->id, ['payments' => [['method' => 'cash', 'amount' => 135000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $p->id, 'quantity' => 3]]])['sale'];
        $line = DB::table('sale_record_items')->where('sale_record_id', $sale->id)->first();
        $returns = app(\App\Services\Shop\ReturnService::class);
        $returns->create($sale, [['sale_item_id' => $line->id, 'quantity' => 1, 'restock' => false]], $t['user']->id, 'Faulty');
        $returns->create($sale->fresh(), [['sale_item_id' => $line->id, 'quantity' => 1, 'restock' => true]], $t['user']->id, 'Changed mind');
        $this->assertEquals(8, (float) $p->fresh()->current_quantity);

        $sales->void($sale->fresh(), 'Entered twice', $t['user']->id);
        $this->assertEquals(9, (float) $p->fresh()->current_quantity, 'the good ones are back on the shelf; the faulty one stays written off');
        $this->assertSame(0.0, round((float) DB::table('payments')->where('sale_record_id', $sale->id)->sum('amount'), 2), 'all the money is handed back');
    }
}

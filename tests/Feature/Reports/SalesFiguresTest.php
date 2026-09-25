<?php

namespace Tests\Feature\Reports;

use App\Admin\Widgets\ReturnsReportWidget;
use App\Admin\Widgets\SalesAnalyticsWidget;
use App\Models\Company;
use App\Models\SaleRecord;
use App\Services\FinancialReportService;
use App\Services\Notifications\ScheduledNotifications;
use App\Services\Reports\ReportService;
use App\Services\Shop\ReturnService;
use App\Services\Shop\SaleService;
use App\Services\Shop\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One day in a Kampala shop, seen by every report:
 *  - web sale: Rice ×2 (10,000) + Soap ×3 (3,000), paid 13,000; later 1 Soap returned (refund 1,000)
 *  - old-app sale: Soap ×2 (2,000) — a bare Sale movement
 *  - a voided sale: Rice ×1 (5,000)
 * Net: 2 sales, 14,000 revenue, profit 2,000 + 800 (soap 2×400) + 800 (old app) = 3,600, units 2 + 2 + 2 = 6.
 */
class SalesFiguresTest extends ReportsTestCase
{
    private SaleRecord $sale;

    protected function setUp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-25 09:00:00', 'UTC')); // 12:00 in Kampala
        parent::setUp();

        $this->sale = $this->sell([[$this->rice, 2], [$this->soap, 3]], 13000);
        $soapLine = $this->sale->saleRecordItems->firstWhere('stock_item_id', $this->soap->id);
        (new ReturnService())->create($this->sale, [['sale_item_id' => $soapLine->id, 'quantity' => 1, 'restock' => true]], $this->userId, 'Changed mind');
        $this->oldAppSale($this->soap, 2);
        $void = $this->sell([[$this->rice, 1]], 5000);
        (new SaleService())->void($void, 'mistake', $this->userId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sales_analytics_widget_counts_each_sale_once_net_of_returns_and_voids(): void
    {
        $d = (new SalesAnalyticsWidget())->data($this->companyId);

        foreach (['today', 'week', 'month'] as $period) {
            $o = $d['overview'][$period];
            $this->assertSame(2, $o['transactions'], "{$period}: voided sale excluded, multi-line sale counted once");
            $this->assertEquals(14000, $o['revenue'], "{$period}: not multiplied by line count, return netted, old app included");
            $this->assertEquals(3600, $o['profit'], "{$period}: stored profit");
            $this->assertEquals(6, $o['units_sold'], "{$period}: reversal rows not counted as sales");
        }
        $this->assertEquals(7000, $d['overview']['today']['avg_transaction']);

        $top = collect($d['top_products'])->keyBy('name');
        $this->assertEquals(2, (float) $top['Rice']->total_sold);
        $this->assertEquals(10000, (float) $top['Rice']->total_revenue);
        $this->assertEquals(2000, (float) $top['Rice']->total_profit);
        $this->assertEquals(4, (float) $top['Soap']->total_sold);
        $this->assertEquals(4000, (float) $top['Soap']->total_revenue);
        $this->assertEquals(1600, (float) $top['Soap']->total_profit);

        $cats = collect($d['category_breakdown'])->keyBy('name');
        $this->assertEquals(10000, $cats['Food']['sales']);
        $this->assertEquals(4000, $cats['Home']['sales']);

        $this->assertCount(1, $d['last_30_days']);
        $day = $d['last_30_days'][0];
        $this->assertSame('2026-09-25', (string) $day->date);
        $this->assertSame(2, (int) $day->sales);
        $this->assertEquals(14000, (float) $day->revenue);
        $this->assertEquals(3600, (float) $day->profit);

        $this->assertSame(['25 Sep'], $d['daily_sales']['labels']);
        $this->assertSame([2], $d['daily_sales']['transactions']);
        $this->assertEquals([14000], $d['daily_sales']['revenue']);

        $this->assertCount(3, $d['monthly_comparison']);
        $this->assertSame('Sep 2026', $d['monthly_comparison'][2]['label']);
        $this->assertEquals(14000, $d['monthly_comparison'][2]['revenue']);
        $this->assertSame(2, $d['monthly_comparison'][2]['transactions']);
        $this->assertCount(12, $d['trends']['labels']);
        $this->assertEquals(14000, end($d['trends']['revenue']));
    }

    public function test_a_fully_returned_sale_is_not_a_transaction(): void
    {
        $sale = $this->sell([[$this->soap, 1]], 1000);
        $line = $sale->saleRecordItems->first();
        (new ReturnService())->create($sale, [['sale_item_id' => $line->id, 'quantity' => 1, 'restock' => true]], $this->userId);

        $o = (new SalesAnalyticsWidget())->data($this->companyId)['overview']['today'];
        $this->assertSame(2, $o['transactions']);
        $this->assertEquals(14000, $o['revenue']);
        $this->assertEquals(7000, $o['avg_transaction']);
    }

    public function test_shop_reports_include_old_app_sales_and_net_returns(): void
    {
        $r = new ReportService();
        $day = $r->run($this->companyId, 'sales_summary', '2026-09-25', '2026-09-25');
        $this->assertSame(2, (int) $day['totals']['sales']);
        $this->assertEquals(14000, $day['totals']['amount']);

        $profit = $r->run($this->companyId, 'profit', '2026-09-25', '2026-09-25');
        $this->assertEquals(14000, $profit['totals']['revenue']);
        $this->assertEquals(3600, $profit['totals']['profit']);
        $this->assertEquals(10400, $profit['totals']['cost']);

        $fast = collect($r->run($this->companyId, 'fast_movers', '2026-09-25', '2026-09-25')['rows'])->keyBy('name');
        $this->assertEquals(4, $fast['Soap']['quantity']);
        $this->assertEquals(2, $fast['Rice']['quantity']);

        $byProduct = collect($r->run($this->companyId, 'sales_summary', '2026-09-25', '2026-09-25', ['group_by' => 'category'])['rows'])->keyBy('label');
        $this->assertEquals(10000, $byProduct['Food']['amount']);
        $this->assertEquals(4000, $byProduct['Home']['amount']);

        Company::withoutGlobalScopes()->where('id', $this->companyId)->update(['tax_rate' => 18]);
        $vat = $r->run($this->companyId, 'vat_summary', '2026-09-25', '2026-09-25');
        $this->assertEquals(14000, $vat['rows'][0]['gross'], 'old-app sales carry VAT too');
    }

    public function test_payments_by_method_keep_the_stored_sign_of_reversals(): void
    {
        $customer = \App\Models\Customer::create(['company_id' => $this->companyId, 'name' => 'Amina', 'phone' => '0772000111', 'created_by_id' => $this->userId]);
        $credit = $this->sell([[$this->rice, 1]], 5000, ['method' => 'mobile_money', 'customer_id' => $customer->id]);
        $payment = $credit->payments->first();
        $contra = (new \App\Services\Shop\PaymentService())->reverse($payment, 'bounced', $this->userId);
        $this->assertEquals(-5000, (float) $contra->amount);

        $rows = collect((new ReportService())->run($this->companyId, 'sales_summary', '2026-09-25', '2026-09-25', ['group_by' => 'method'])['rows'])->keyBy('label');
        $this->assertEquals(0, $rows['Mobile Money']['amount'], 'a reversal is stored negative; adding it back doubled the payment');
        $this->assertEquals(12000 + 2000, $rows['Cash']['amount'], 'cash less the refund, plus the old-app sale');
        $this->assertEquals(5000, $rows['On credit (still owed)']['amount']);
    }

    public function test_financial_report_uses_stored_profit_and_skips_deleted_and_stock_purchase_expenses(): void
    {
        $this->expense(1000);
        $this->expense(700, null, true); // soft-deleted
        $this->expense(20000, 'goods_receipt'); // stock bought: cost of goods, not an operating expense
        $svc = new FinancialReportService();
        $from = '2026-09-01';
        $to = '2026-09-30';

        $inv = $svc->calculateInventoryData($this->companyId, $from, $to);
        $this->assertEquals(14000, $inv['inventory_total_selling_price']);
        $this->assertEquals(3600, $inv['inventory_total_earned_profit']);
        $this->assertEquals(10400, $inv['inventory_total_cost']);
        $this->assertSame(2, $inv['sales_count']);

        $fin = $svc->calculateFinancialData($this->companyId, $from, $to);
        $this->assertEquals(21000, $fin['total_expense'], 'deleted expense left out');

        $sum = $svc->getSummaryStatistics($this->companyId, $from, $to);
        $this->assertEquals(1000, $sum['operating_expenses']);
        $this->assertEquals(3600 - 1000, $sum['overall_profit'], 'stock purchase not deducted on top of cost of goods sold');

        $cats = collect($svc->getInventoryCategories($this->companyId, $from, $to))->keyBy('name');
        $this->assertEquals(10000, (float) $cats['Food']->total_sales);
        $this->assertEquals(8000, (float) $cats['Food']->total_investment);
        $this->assertEquals(2000, (float) $cats['Food']->profit);
        $this->assertEquals(1600, (float) $cats['Home']->profit);

        // A sale outside the range must not leak in through the join.
        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00', 'UTC'));
        $this->sell([[$this->rice, 1]], 5000);
        Carbon::setTestNow(Carbon::parse('2026-09-25 09:00:00', 'UTC'));
        $svc->clearCache($this->companyId);
        $this->assertEquals(10000, (float) collect($svc->getInventoryCategories($this->companyId, $from, $to))->keyBy('name')['Food']->total_sales);

        DB::table('stock_items')->where('id', $this->soap->id)->update(['is_deleted' => 1]);
        $products = collect($svc->getInventoryProducts($this->companyId, $from, $to))->keyBy('name');
        $this->assertFalse($products->has('Soap'), 'deleted products are not listed');
        $this->assertEquals(10000, (float) $products['Rice']->revenue);
    }

    public function test_financial_report_cache_is_cleared_per_company(): void
    {
        $svc = new FinancialReportService();
        $this->assertEquals(14000, $svc->calculateInventoryData($this->companyId, '2026-09-01', '2026-09-30')['inventory_total_selling_price']);
        $this->oldAppSale($this->rice, 1);
        $this->assertEquals(14000, $svc->calculateInventoryData($this->companyId, '2026-09-01', '2026-09-30')['inventory_total_selling_price'], 'cached');
        $svc->clearCache($this->companyId);
        $this->assertEquals(19000, $svc->calculateInventoryData($this->companyId, '2026-09-01', '2026-09-30')['inventory_total_selling_price']);
    }

    public function test_whatsapp_daily_summary_counts_every_sale_net_of_refunds(): void
    {
        $company = Company::withoutGlobalScopes()->find($this->companyId);
        $d = (new ScheduledNotifications())->daySales($company, now()->setTimezone('Africa/Kampala'));
        $this->assertSame(2, $d['count']);
        $this->assertEquals(14000, $d['total']);
        $this->assertEquals(14000, $d['cash']);
        $this->assertEquals(0, $d['credit']);
        $this->assertSame('Rice', $d['top']);
    }

    public function test_api_dashboard_month_figures(): void
    {
        $this->expense(900, null, true);
        $this->expense(400);
        $user = \App\Models\User::find($this->userId);
        $request = \Illuminate\Http\Request::create('/api/v1/dashboard');
        $request->setUserResolver(fn () => $user);
        $data = (new \App\Http\Controllers\Api\V1\DashboardController())->index($request)->getData(true)['data'];
        $this->assertSame(2, $data['sales']['this_month_count']);
        $this->assertEquals(14000, $data['sales']['this_month_revenue']);
        $this->assertEquals(400, $data['finance']['total_expense']);
    }

    public function test_returns_report_counts_returns_not_lines_and_values_old_return_movements(): void
    {
        // One sale return with two items is one return.
        $sale = $this->sell([[$this->rice, 1], [$this->soap, 1]], 6000);
        $lines = $sale->saleRecordItems->map(fn ($l) => ['sale_item_id' => $l->id, 'quantity' => 1, 'restock' => true])->all();
        (new ReturnService())->create($sale, $lines, $this->userId, 'Wrong order');
        // An old stand-alone Return movement (value not stored) and one that was reversed.
        (new StockService())->record(['stock_item_id' => $this->soap->id, 'type' => 'Return', 'quantity' => 2, 'created_by_id' => $this->userId]);
        $undone = (new StockService())->record(['stock_item_id' => $this->rice->id, 'type' => 'Return', 'quantity' => 1, 'created_by_id' => $this->userId]);
        (new StockService())->reverse($undone, 'typo', $this->userId);

        $s = (new ReturnsReportWidget())->data($this->companyId)['summary']['today'];
        $this->assertSame(3, (int) $s->returns_count, 'setUp return + 2-item return + old movement; the reversed one is out');
        $this->assertEquals(1 + 2 + 2, (float) $s->units_returned);
        $this->assertEquals(1000 + 6000 + 2000, (float) $s->refund_total, 'old Return movement valued at quantity × price');
    }
}

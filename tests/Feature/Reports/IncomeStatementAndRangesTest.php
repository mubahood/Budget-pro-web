<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\FinancialRecord;
use App\Services\Dashboard\DashboardService;
use App\Services\Reports\ReportFormat;
use App\Services\Reports\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The income statement report (classic FinancialReport P&L) and the report date presets. */
class IncomeStatementAndRangesTest extends ReportsTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function otherIncome(float $amount): void
    {
        $cat = FinancialCategory::withoutGlobalScopes()->create(['company_id' => $this->companyId, 'name' => 'Rent received', 'type' => 'Income']);
        $r = new FinancialRecord();
        $r->forceFill(['financial_category_id' => $cat->id, 'company_id' => $this->companyId, 'user_id' => $this->userId, 'created_by_id' => $this->userId,
            'amount' => $amount, 'quantity' => 1, 'type' => 'Income', 'payment_method' => 'cash', 'recipient' => '', 'description' => 'back room', 'receipt' => '', 'date' => now()]);
        $r->save();
    }

    public function test_income_statement_is_sales_less_cost_plus_other_income_less_running_expenses(): void
    {
        $this->sell([[$this->rice, 2], [$this->soap, 5]], 15000); // sales 15,000; cost 8,000 + 3,000
        $this->expense(3000);
        $this->expense(7000, 'goods_receipt'); // stock bought: cost of goods when sold, not an expense
        $this->expense(900, null, true);       // deleted: left out
        $this->otherIncome(1500);
        $today = now('Africa/Kampala')->toDateString();
        $from = now('Africa/Kampala')->subDay()->toDateString(); // the helpers date ledger rows by the UTC day

        $r = (new ReportService())->run($this->companyId, 'income_statement', $from, $today);
        $this->assertSame('Income statement (profit & loss)', $r['title']);
        $lines = array_column($r['rows'], 'amount', 'line');
        $this->assertEquals(15000, $lines['Sales (net of returns)']);
        $this->assertEquals(-11000, $lines['Less: cost of the goods sold']);
        $this->assertEquals(4000, $lines['Gross profit']);
        $this->assertEquals(1500, $lines['Add: other income'], 'sale payments in the ledger are not other income');
        $this->assertEquals(-3000, $lines['Less: running expenses']);
        $this->assertEquals(2500, $lines['Net profit']);
        $this->assertEquals(2500, $r['meta']['net_profit']);

        // The same gross profit as the profit report.
        $profit = (new ReportService())->run($this->companyId, 'profit', $from, $today);
        $this->assertEquals($profit['totals']['profit'], $r['meta']['gross_profit']);
        $this->assertEquals($profit['totals']['revenue'], $r['meta']['sales']);

        $sections = collect($r['sections'])->keyBy('key');
        $this->assertSame(['categories', 'records', 'sales_by_category', 'sales_by_product'], $sections->keys()->all());
        $byCat = array_column($sections['sales_by_category']['rows'], null, 'name');
        $this->assertEquals(10000, $byCat['Food']['sales']);
        $this->assertEquals(2000, $byCat['Food']['profit']);
        $this->assertEquals(5000, $byCat['Home']['sales']);
        $this->assertEquals(15000, $sections['sales_by_category']['totals']['sales'], 'categories add up to sales');
        $this->assertEqualsCanonicalizing(['Rice', 'Soap'], array_column($sections['sales_by_product']['rows'], 'name'), 'only products that sold');
        $accounts = array_column($sections['categories']['rows'], null, 'name');
        $this->assertEquals(1500, $accounts['Rent received']['income']);
        $this->assertNotContains(-900.0, array_column($sections['records']['rows'], 'amount'), 'deleted entries stay out');
        $this->assertContains(-3000.0, array_column($sections['records']['rows'], 'amount'), 'expenses are signed');

        // Live, not the five-minute PDF cache: a later sale shows at once.
        $this->sell([[$this->soap, 1]], 1000);
        $this->assertEquals(16000, (new ReportService())->run($this->companyId, 'income_statement', $from, $today)['meta']['sales']);

        // PDF and Excel carry the sections too.
        [$pdf, $type] = ReportFormat::render($r, 'pdf');
        $this->assertSame('application/pdf', $type);
        $this->assertStringStartsWith('%PDF', $pdf);
        [$xlsx] = ReportFormat::render($r, 'xlsx');
        $zip = tempnam(sys_get_temp_dir(), 'is');
        file_put_contents($zip, $xlsx);
        $sheet = (string) file_get_contents('zip://'.$zip.'#xl/worksheets/sheet1.xml');
        @unlink($zip);
        $this->assertStringContainsString('Stock sales and profit by product', $sheet);
        $this->assertStringContainsString('Net profit', $sheet);
    }

    public function test_report_presets_are_calendar_ranges_and_the_dashboard_keeps_its_own(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 10:00:00', 'UTC')); // Sunday 13:00 in Kampala
        $company = Company::withoutGlobalScopes()->findOrFail($this->companyId);
        $d = new DashboardService();
        $span = fn (string $key) => [($r = $d->range($company, $key))['from'], $r['to']];

        $this->assertSame(['2026-09-26', '2026-09-26'], $span('yesterday'), 'yesterday is one day (not the classic report\'s two)');
        $this->assertSame(['2026-09-21', '2026-09-27'], $span('week'), 'Monday to today');
        $this->assertSame(['2026-09-14', '2026-09-20'], $span('last_week'));
        $this->assertSame(['2026-07-01', '2026-09-27'], $span('quarter'));
        $this->assertSame(['2025-01-01', '2025-12-31'], $span('last_year'));
        $this->assertSame(['2026-09-01', '2026-09-27'], $span('month'), 'existing presets unchanged');
        $this->assertSame(['2026-09-21', '2026-09-27'], $span('7d'));
        $this->assertSame('Last week', $d->range($company, 'last_week')['label']);

        $fy = DB::table('financial_periods')->where('company_id', $this->companyId)->where('status', 'Active')->first();
        $period = $d->range($company, 'period');
        $this->assertSame([substr((string) $fy->start_date, 0, 10), substr((string) $fy->end_date, 0, 10)], [$period['from'], $period['to']]);
        $this->assertSame('FY', $period['label']);
        DB::table('financial_periods')->where('id', $fy->id)->update(['status' => 'Inactive']);
        $none = $d->range($company, 'period');
        $this->assertSame(['2026-01-01', '2026-09-27'], [$none['from'], $none['to']]);
        $this->assertSame('This year (no active financial period)', $none['label']);

        $this->assertSame(['today', 'yesterday', '7d', '30d', 'month', 'last_month', 'year'], array_keys(DashboardService::RANGES), 'the dashboards\' chips are unchanged');
        foreach (array_keys(DashboardService::RANGES) as $key) {
            $this->assertArrayHasKey($key, DashboardService::REPORT_RANGES);
        }
        $this->assertSame('today', $d->range($company, 'nonsense')['key']);
    }
}

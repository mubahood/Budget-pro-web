<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\StockItem;
use App\Services\Notifications\ScheduledNotifications;
use App\Services\Reports\ReportService;
use App\Services\Shop\SaleService;
use App\Services\Shop\StockService;
use App\Support\StoreFeatures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket reports (SUPERMARKET_PLAN.md H1–H4, H6) in a Kampala shop (UTC+3), range Mon 21 – Tue 22 Sep 2026:
 *  - Sat 19, 12:00: Rice ×1 (5,000)                     ← the previous period only
 *  - Mon 21, 09:00: Rice ×2 + Soap ×1 = 11,000
 *  - Mon 21, 09:30: Soap ×3 + Gum ×1 = 3,500
 *  - Tue 22, 17:00: old-app Soap ×2 = 2,000 (bare Sale movement, no sale document)
 *  - Tue 22, 17:10: Rice ×1, voided
 *  - Tue 22: Damage Rice ×1 (4,000 at cost), Expired Soap ×2 (1,200), Lost Soap ×1 reversed (not counted)
 * Net: 3 sales, 16,500, 9 items.
 */
class SupermarketReportsTest extends ReportsTestCase
{
    private StockItem $gum;

    protected function setUp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 09:00:00', 'UTC'));
        parent::setUp();
        $home = DB::table('stock_categories')->where('company_id', $this->companyId)->where('name', 'Home')->value('id');
        $wash = DB::table('stock_sub_categories')->where('company_id', $this->companyId)->where('name', 'Wash')->value('id');
        $this->gum = $this->product('Gum', (int) $home, (int) $wash, 300, 500);
        $this->sell([[$this->rice, 1]], 5000);

        Carbon::setTestNow(Carbon::parse('2026-09-21 06:00:00', 'UTC'));
        $this->sell([[$this->rice, 2], [$this->soap, 1]], 11000);
        Carbon::setTestNow(Carbon::parse('2026-09-21 06:30:00', 'UTC'));
        $this->sell([[$this->soap, 3], [$this->gum, 1]], 3500);

        Carbon::setTestNow(Carbon::parse('2026-09-22 14:00:00', 'UTC'));
        $this->oldAppSale($this->soap, 2);
        Carbon::setTestNow(Carbon::parse('2026-09-22 14:10:00', 'UTC'));
        (new SaleService())->void($this->sell([[$this->rice, 1]], 5000), 'mistake', $this->userId);

        $stock = new StockService();
        $stock->record(['stock_item_id' => $this->rice->id, 'type' => 'Damage', 'quantity' => 1, 'created_by_id' => $this->userId]);
        $stock->record(['stock_item_id' => $this->soap->id, 'type' => 'Expired', 'quantity' => 2, 'created_by_id' => $this->userId]);
        $lost = $stock->record(['stock_item_id' => $this->soap->id, 'type' => 'Lost', 'quantity' => 1, 'reason' => 'theft', 'created_by_id' => $this->userId]);
        $stock->reverse($lost, 'found it', $this->userId);

        DB::table('shifts')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->companyId, 'number' => 'S-1', 'opened_by_id' => $this->userId,
            'opened_at' => '2026-09-22 05:00:00', 'closed_by_id' => $this->userId, 'closed_at' => '2026-09-22 15:00:00', 'status' => 'closed',
            'expected_cash' => 2000, 'counted_cash' => 1500, 'variance' => -500, 'created_at' => now(), 'updated_at' => now()]);
        if (Schema::hasTable('approvals')) {
            DB::table('approvals')->insert(['company_id' => $this->companyId, 'action' => 'price_override', 'requested_by' => $this->userId, 'approved_by' => $this->userId, 'created_at' => now()]);
        }
        if (Schema::hasTable('cash_movements')) {
            DB::table('cash_movements')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->companyId, 'shift_id' => 1, 'type' => 'no_sale',
                'amount' => 0, 'reason' => 'change', 'created_by' => $this->userId, 'created_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function report(string $name): array
    {
        return app(ReportService::class)->run($this->companyId, $name, '2026-09-21', '2026-09-22');
    }

    public function test_category_margin_with_share_and_growth_against_the_previous_period(): void
    {
        $r = $this->report('category_margin');
        $rows = collect($r['rows'])->keyBy('label');

        $this->assertEquals(10000, $rows['Food']['sales']);
        $this->assertEquals(8000, $rows['Food']['cost']);
        $this->assertEquals(20.0, $rows['Food']['margin']);
        $this->assertEquals(60.6, $rows['Food']['share']);
        $this->assertEquals(5000, $rows['Food']['previous'], 'Sat 19 is the 2 days before');
        $this->assertEquals(100.0, $rows['Food']['growth']);
        // Home: Soap 1 + 3 + 2 (old app, counted once) = 6,000 + Gum 500.
        $this->assertEquals(6500, $rows['Home']['sales']);
        $this->assertEquals(2600, $rows['Home']['profit']);
        $this->assertNull($rows['Home']['growth'], 'nothing sold before: no growth figure');
        $this->assertEquals(16500, $r['totals']['sales']);
        $this->assertSame('2026-09-19', $r['meta']['previous_from']);

        $subs = collect($r['sections'][0]['rows'])->keyBy('label');
        $this->assertEquals(6500, $subs['Home › Wash']['sales']);
        $this->assertEquals(10000, $subs['Food › Dry']['sales']);
    }

    public function test_basket_counts_each_sale_once_with_a_local_hour_heatmap(): void
    {
        $r = $this->report('basket');

        $this->assertSame(3, $r['totals']['sales'], 'two web sales and one old-app sale; the void left out');
        $this->assertEquals(16500, $r['totals']['amount']);
        $this->assertEquals(5500, $r['meta']['avg_basket']);
        $this->assertEquals(3.0, $r['meta']['items_per_basket']);
        $this->assertSame('09:00–10:00', $r['meta']['best_hour'], 'UTC 06:00 is 09:00 in Kampala');
        $this->assertSame('Monday', $r['meta']['best_day']);
        $days = collect($r['rows'])->keyBy('day');
        $this->assertSame(2, $days['Monday']['sales']);
        $this->assertSame(1, $days['Tuesday']['sales']);

        $heat = collect($r['sections'])->firstWhere('key', 'heatmap');
        $this->assertSame('heatmap', $heat['type']);
        $this->assertSame(['day', 'h09', 'h10', 'h11', 'h12', 'h13', 'h14', 'h15', 'h16', 'h17'], array_column($heat['columns'], 'key'));
        $grid = collect($heat['rows'])->keyBy('day');
        $this->assertSame(2, $grid['Mon']['h09']);
        $this->assertSame(1, $grid['Tue']['h17']);
        $this->assertSame(0, $grid['Mon']['h17']);
    }

    public function test_abc_classes_and_days_of_cover(): void
    {
        $r = $this->report('abc');
        $rows = collect($r['rows'])->keyBy('name');

        $this->assertSame(['Rice', 'Soap', 'Gum'], array_column($r['rows'], 'name'));
        $this->assertSame('A', $rows['Rice']['class']);
        $this->assertSame('A', $rows['Soap']['class'], 'starts at 60.6% of the curve');
        $this->assertSame('C', $rows['Gum']['class'], 'starts at 97%');
        $this->assertSame([2, 0, 1], [$r['totals']['class_a'], $r['totals']['class_b'], $r['totals']['class_c']]);
        $this->assertEquals(6, $rows['Soap']['quantity'], 'old-app units counted once');
        $this->assertEquals(3, $rows['Soap']['per_day']);
        $soapOnHand = (float) $this->soap->fresh()->current_quantity;
        $this->assertEquals(round($soapOnHand / 3, 1), $rows['Soap']['cover']);
        $this->assertEquals(round((float) $this->rice->fresh()->current_quantity / 1, 1), $rows['Rice']['cover']);
    }

    public function test_shrink_by_reason_category_and_week_as_a_share_of_sales(): void
    {
        $r = $this->report('shrink');
        $rows = collect($r['rows'])->keyBy('reason');

        $this->assertEquals(4000, $rows['Damaged']['value']);
        $this->assertEquals(1200, $rows['Expired']['value']);
        $this->assertArrayNotHasKey('Stolen', $rows->all(), 'a reversed write-off is not a loss');
        $this->assertEquals(5200, $r['totals']['value']);
        $this->assertEquals(round(5200 * 100 / 16500, 2), $r['totals']['pct_sales']);
        $cats = collect($r['sections'][0]['rows'])->keyBy('category');
        $this->assertEquals(4000, $cats['Food']['value']);
        $this->assertEquals(1200, $cats['Home']['value']);
        $weeks = $r['sections'][1]['rows'];
        $this->assertSame('2026-09-21', $weeks[0]['week']);
        $this->assertEquals(16500, $weeks[0]['sales']);
    }

    public function test_cash_control_per_cashier(): void
    {
        $r = $this->report('cash_control');
        $this->assertCount(1, $r['rows']);
        $row = $r['rows'][0];

        $this->assertSame(3, $row['sales']);
        $this->assertEquals(16500, $row['amount']);
        $this->assertSame(1, $row['voids']);
        $this->assertEquals(5000, $row['voided']);
        $this->assertSame(1, $row['shifts']);
        $this->assertEquals(-500, $row['over_short']);
        $this->assertSame(Schema::hasTable('approvals') ? 1 : 0, $row['overrides']);
        $this->assertSame(Schema::hasTable('cash_movements') ? 1 : 0, $row['no_sales']);
    }

    public function test_daily_flash_lines_only_in_supermarket_mode(): void
    {
        $company = Company::withoutGlobalScopes()->find($this->companyId);
        $local = Carbon::parse('2026-09-22 20:00:00', 'Africa/Kampala');
        $svc = new ScheduledNotifications();

        $plain = $svc->dailySummaryMessage($company, $local);
        $this->assertStringNotContainsString('Customers served', $plain['body']);
        $this->assertArrayNotHasKey('flash', $plain['data']);

        DB::table('stock_batches')->insert(['company_id' => $this->companyId, 'stock_item_id' => $this->soap->id, 'batch_number' => 'B1',
            'expiry_date' => '2026-09-27', 'quantity' => 3, 'unit_cost' => 600, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_batches')->insert(['company_id' => $this->companyId, 'stock_item_id' => $this->soap->id, 'batch_number' => 'B2',
            'expiry_date' => '2026-12-31', 'quantity' => 5, 'unit_cost' => 600, 'created_at' => now(), 'updated_at' => now()]);
        StoreFeatures::update($company, ['mode' => true]);
        $m = $svc->dailySummaryMessage($company->fresh(), $local);

        $f = $m['data']['flash'];
        $this->assertSame(1, $f['customers'], 'the old-app sale; the void left out');
        $this->assertEquals(2000, $f['avg_basket']);
        $this->assertSame(1, $f['shifts_closed']);
        $this->assertEquals(-500, $f['over_short']);
        $this->assertSame(1, $f['top_voids']['count']);
        $this->assertEquals(1800, $f['short_dated_value'], 'only the batch within 14 days');
        $this->assertStringStartsWith($plain['body'], $m['body'], 'the usual summary is unchanged');
        $this->assertStringContainsString('Customers served: 1', $m['body']);
        $this->assertStringContainsString('Cash over/short: -500 UGX', $m['body']);
        $this->assertStringContainsString('Most voids: ', $m['body']);
    }
}

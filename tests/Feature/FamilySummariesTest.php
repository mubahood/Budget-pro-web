<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Budget\BudgetSummary;
use App\Services\Onboarding\RegistrationService;
use App\Services\Poultry\PoultrySummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The portal's poultry and budget cards (budget-pro-new docs/FAMILY_PLAN.md Part 2): figures from rows
 * as the phone writes them, with the phone's definitions (budget-pro-mobo lib/poultry/domain).
 */
class FamilySummariesTest extends TestCase
{
    use DatabaseTransactions;

    /** 10:00 on 17 October 2026 in Kampala. */
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-17 10:00:00', 'Africa/Kampala');
    }

    private function account(array $modules): Company
    {
        return app(RegistrationService::class)->register(['first_name' => 'Grace', 'last_name' => 'Nsubuga', 'email' => 'g'.uniqid('', true).'@example.test',
            'password' => 'secret123', 'company_name' => 'Nsubuga Farm', 'currency' => 'UGX', 'country' => 'UG', 'timezone' => 'Africa/Kampala',
            'modules' => $modules], RegistrationService::PRODUCT_BUDGET, 'api')['company'];
    }

    private function row(string $table, array $values): int
    {
        return (int) DB::table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_farm_with_no_batch_is_empty_and_costs_one_query(): void
    {
        $c = $this->account(['poultry']);
        DB::enableQueryLog();
        $s = (new PoultrySummary)->for($c, $this->now);
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'poultry_')));
        DB::disableQueryLog();
        $this->assertFalse($s['has_batches']);
        $this->assertSame(0, $s['birds_alive']);
        $this->assertNull($s['lay_rate_7d']);
    }

    public function test_poultry_figures_follow_the_phones_definitions(): void
    {
        $c = $this->account(['poultry']);
        $cid = (int) $c->id;
        $layers = $this->row('poultry_batches', ['company_id' => $cid, 'name' => 'Layers A', 'type' => 'layer', 'acquired_date' => '2026-03-01', 'start_count' => 320, 'status' => 'laying']);
        $broilers = $this->row('poultry_batches', ['company_id' => $cid, 'name' => 'Broilers 1', 'type' => 'broiler', 'acquired_date' => '2026-09-20', 'start_count' => 100, 'status' => 'active']);
        $this->row('poultry_batches', ['company_id' => $cid, 'name' => 'Old flock', 'type' => 'layer', 'acquired_date' => '2025-01-01', 'start_count' => 50, 'status' => 'closed']);
        $this->row('poultry_batches', ['company_id' => $cid, 'name' => 'Deleted', 'type' => 'layer', 'acquired_date' => '2026-01-01', 'start_count' => 999, 'is_deleted' => 1]);

        // Layers: 6 trays + 10 = 190 eggs a day for the last 3 days (today included), 2 deaths each day,
        // and a record 20 days ago (outside the window) with 5 deaths.
        foreach (['2026-10-17', '2026-10-16', '2026-10-15'] as $d) {
            $this->row('poultry_daily_records', ['company_id' => $cid, 'batch_id' => $layers, 'date' => $d, 'eggs_trays' => 6, 'eggs_loose' => 10, 'mortality' => 2, 'feed_kg' => 30]);
        }
        $this->row('poultry_daily_records', ['company_id' => $cid, 'batch_id' => $layers, 'date' => '2026-09-27', 'eggs_trays' => 5, 'mortality' => 5, 'feed_kg' => 30]);
        $this->row('poultry_daily_records', ['company_id' => $cid, 'batch_id' => $layers, 'date' => '2026-10-14', 'eggs_trays' => 99, 'mortality' => 50, 'feed_kg' => 500, 'is_deleted' => 1]);
        // Broilers: 1 death in a record, 3 in a mortality event (in the window), 10 sold as broilers.
        $this->row('poultry_daily_records', ['company_id' => $cid, 'batch_id' => $broilers, 'date' => '2026-10-16', 'mortality' => 1, 'feed_kg' => 10]);
        $this->row('poultry_mortality_events', ['company_id' => $cid, 'batch_id' => $broilers, 'count' => 3, 'date' => '2026-10-12']);
        $this->row('poultry_mortality_events', ['company_id' => $cid, 'batch_id' => $layers, 'count' => 4, 'date' => '2026-09-01']); // before the window
        $this->row('poultry_sales', ['company_id' => $cid, 'batch_id' => $broilers, 'category' => 'broilers', 'qty' => 10, 'unit_price' => 20000, 'total' => 200000, 'amount_paid' => 200000, 'date' => '2026-10-10']);
        $this->row('poultry_sales', ['company_id' => $cid, 'batch_id' => $layers, 'category' => 'eggs', 'qty' => 20, 'unit' => 'tray', 'unit_price' => 12000, 'total' => 240000, 'amount_paid' => 100000, 'date' => '2026-10-02']);
        $this->row('poultry_sales', ['company_id' => $cid, 'batch_id' => $layers, 'category' => 'eggs', 'qty' => 5, 'unit' => 'tray', 'total' => 60000, 'date' => '2026-09-30']); // last month
        $this->row('poultry_expenses', ['company_id' => $cid, 'category' => 'medicine', 'amount' => 45000, 'date' => '2026-10-05']);
        $this->row('poultry_expenses', ['company_id' => $cid, 'category' => 'labor', 'amount' => 70000, 'date' => '2026-09-28']); // last month
        // Feed: 600 kg bought, 20 kg taken out, +5 kg adjustment; eaten so far 30×4 + 10 = 130 kg.
        $feed = $this->row('poultry_feed_types', ['company_id' => $cid, 'name' => 'Layers mash', 'kg_per_bag' => 70]);
        $this->row('poultry_feed_stock', ['company_id' => $cid, 'feed_type_id' => $feed, 'direction' => 'in', 'qty_kg' => 600, 'cost' => 900000, 'date' => '2026-09-25']);
        $this->row('poultry_feed_stock', ['company_id' => $cid, 'feed_type_id' => $feed, 'direction' => 'out', 'qty_kg' => 20, 'date' => '2026-10-01']);
        $this->row('poultry_feed_stock', ['company_id' => $cid, 'feed_type_id' => $feed, 'direction' => 'adjust', 'qty_kg' => 5, 'date' => '2026-10-02']);

        $s = (new PoultrySummary)->for($c->fresh(), $this->now);

        $this->assertTrue($s['has_batches']);
        $this->assertSame([3, 2], [$s['batches_total'], $s['batches_active']], 'the deleted batch is left out');
        // Layers: 320 − (2×3 + 5) − 4 = 305. Broilers: 100 − 1 − 3 − 10 = 86.
        $this->assertSame(305, $s['layer_birds']);
        $this->assertSame(305 + 86, $s['birds_alive']);
        $this->assertSame(190, $s['eggs_today']);
        $this->assertSame(570, $s['eggs_7d']);
        $this->assertSame(round(190 / 305 * 100, 1), $s['lay_rate_today']);
        $this->assertSame(round(570 / 3 / 305 * 100, 1), $s['lay_rate_7d'], 'the average recorded day');
        $this->assertSame(2 * 3 + 1 + 3, $s['deaths_7d']);
        $this->assertSame(600.0 - 20 + 5 - 130, $s['feed_kg']);
        // The average day over the latest records: 130 kg over 4 recorded dates = 32.5 kg; 455 / 32.5 = 14 days.
        $this->assertSame(14, $s['feed_days_left']);
        $this->assertFalse($s['feed_low']);
        $this->assertSame(440000.0, $s['sales_month']);
        $this->assertSame(45000.0, $s['expenses_month']);
        $this->assertSame(['Broilers 1', 'Layers A'], array_column($s['batches'], 'name'), 'active batches, newest first');
        $this->assertSame(32, $s['batches'][1]['age_weeks']);
        $this->assertNotContains('Old flock', array_column($s['batches'], 'name'));

        // Feed runs low: 7 days or fewer.
        DB::table('poultry_feed_stock')->where('company_id', $cid)->where('direction', 'in')->update(['qty_kg' => 250]);
        $low = (new PoultrySummary)->for($c->fresh(), $this->now);
        $this->assertSame(105.0, $low['feed_kg']);
        $this->assertSame(3, $low['feed_days_left']);
        $this->assertTrue($low['feed_low']);
    }

    public function test_a_broiler_farm_has_no_lay_rate(): void
    {
        $c = $this->account(['poultry']);
        $b = $this->row('poultry_batches', ['company_id' => $c->id, 'name' => 'Broilers', 'type' => 'broiler', 'acquired_date' => '2026-10-01', 'start_count' => 200]);
        $this->row('poultry_daily_records', ['company_id' => $c->id, 'batch_id' => $b, 'date' => '2026-10-17', 'feed_kg' => 12]);
        $s = (new PoultrySummary)->for($c, $this->now);
        $this->assertSame(200, $s['birds_alive']);
        $this->assertSame(0, $s['layer_birds']);
        $this->assertNull($s['lay_rate_today']);
        $this->assertNull($s['lay_rate_7d']);
        $this->assertSame(0, $s['feed_days_left'], 'no feed bought: none left');
        $this->assertTrue($s['feed_low']);
    }

    public function test_budget_figures_per_open_event(): void
    {
        $c = $this->account(['budget']);
        $cid = (int) $c->id;
        $wedding = $this->row('budget_programs', ['company_id' => $cid, 'name' => 'Brian & Grace Wedding', 'status' => 'Active', 'deadline' => '2027-01-02']);
        $intro = $this->row('budget_programs', ['company_id' => $cid, 'name' => 'Kwanjula', 'status' => 'Active', 'deadline' => '2026-10-20']);
        $this->row('budget_programs', ['company_id' => $cid, 'name' => 'Closed event', 'status' => 'Inactive', 'deadline' => '2026-12-01']);
        $this->row('budget_programs', ['company_id' => $cid, 'name' => 'Old one', 'status' => 'Active', 'deadline' => '2026-08-01']);
        $this->row('budget_programs', ['company_id' => $cid, 'name' => 'Deleted one', 'status' => 'Active', 'is_deleted' => 1]);

        $cat = $this->row('budget_item_categories', ['company_id' => $cid, 'budget_program_id' => $wedding, 'name' => 'Venue']);
        $this->row('budget_items', ['company_id' => $cid, 'budget_program_id' => $wedding, 'budget_item_category_id' => $cat, 'name' => 'Hall', 'unit_price' => 5000000, 'quantity' => 1, 'target_amount' => 5000000, 'invested_amount' => 2000000]);
        $this->row('budget_items', ['company_id' => $cid, 'budget_program_id' => $wedding, 'budget_item_category_id' => $cat, 'name' => 'Chairs', 'unit_price' => 5000, 'quantity' => 400, 'target_amount' => 2000000, 'invested_amount' => 0]);
        $this->row('budget_items', ['company_id' => $cid, 'budget_program_id' => $wedding, 'budget_item_category_id' => $cat, 'name' => 'Gone', 'target_amount' => 9000000, 'is_deleted' => 1]);
        foreach ([['Uncle Sam', 1000000, 1000000, 0], ['Aunt Rose', 500000, 200000, 300000], ['Kato', 2000000, 0, 2000000]] as [$name, $amount, $paid, $owed]) {
            $this->row('contribution_records', ['company_id' => $cid, 'budget_program_id' => $wedding, 'name' => $name, 'amount' => $amount, 'paid_amount' => $paid, 'not_paid_amount' => $owed]);
        }

        $s = (new BudgetSummary)->for($c, $this->now);
        $this->assertSame(3, $s['open_count'], 'the inactive and the deleted event are left out');
        $this->assertSame(['Kwanjula', 'Brian & Grace Wedding', 'Old one'], array_column($s['events'], 'name'), 'next day first, past last');

        $w = $s['events'][1];
        $this->assertSame([$wedding, '2027-01-02', 77], [$w['id'], $w['date'], $w['days_left']]);
        $this->assertSame([7000000.0, 2000000.0], [$w['budget'], $w['spent']]);
        $this->assertSame([3500000.0, 1200000.0, 2300000.0], [$w['pledged'], $w['paid'], $w['owed']]);
        $this->assertSame(5800000.0, $w['still_needed']);
        $this->assertSame(3500000.0, $w['not_pledged']);
        $this->assertSame(11, $w['weeks_left']);
        $this->assertSame(round(5800000 / 11, 2), $w['per_week']);
        $this->assertSame(3, $w['pledges']);

        $k = $s['events'][0];
        $this->assertSame([$intro, 3, 1, 0.0, null], [$k['id'], $k['days_left'], $k['weeks_left'], $k['budget'], $k['per_week']]);
        $this->assertSame(-77, $s['events'][2]['days_left']);
        $this->assertNull($s['events'][2]['per_week'], 'the day has passed');

        $this->assertSame(['open_count' => 0, 'events' => []], (new BudgetSummary)->for($this->account(['budget']), $this->now));
    }
}

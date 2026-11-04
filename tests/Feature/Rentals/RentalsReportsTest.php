<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalMaintenance;
use App\Models\RentalTenant;
use App\Models\User;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use App\Services\Rentals\RentalsModule;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Every report of RentalReports (docs/estate-ledger/04-architecture.md §17) against one small set of books with every figure
 * worked out by hand; the landlord statement reconciles to the journal; CSV, XLSX and PDF all open; a formula in a
 * name is neutralised; a caretaker sees their own; a period holds the whole of its last day; a deposit is never income;
 * occupancy comes from the leases, never from what was last saved.
 *
 * The books, March 2026, 10 % commission, today 31 March:
 *   A1  rent 300,000, deposit 300,000, garbage 10,000 kept by the agency. Paid 610,000 cash on 1 March.
 *   A2  rent 200,000, fixed term to 30 April. Paid 50,000 mobile money on 31 March (rent overdue: 150,000 left).
 *   A3  vacant, asking 100,000.
 *   Repairs 20,000 cash on 10 March, charged to the landlord (from a repair ticket). Office 5,000 cash on 15 March, the agency's.
 *   Payout 100,000 cash on 20 March. A second ticket opened at 23:30 on 31 March, still open.
 *
 *   Cash in hand: 610,000 − 20,000 − 5,000 − 100,000 = 485,000, plus 50,000 mobile money = 535,000.
 *   Landlord: 270,000 + 45,000 − 20,000 − 100,000 = 195,000. Deposits 300,000. Income 30,000 + 5,000 + 10,000 = 45,000. Own expenses 5,000.
 *   535,000 = 195,000 + 300,000 + 45,000 − 5,000.
 */
class RentalsReportsTest extends ApiTestCase
{
    use MakesRentals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function books(): object
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$p, $units] = $this->building($owner, 3, 100000);
        $units[0]->forceFill(['rent_minor' => 300000])->save();
        $units[1]->forceFill(['rent_minor' => 200000])->save();
        $l1 = $this->tenantIn($owner, $units[0]->fresh(), '2026-03-01', ['deposit_minor' => 300000, 'items' => [['name' => 'Garbage', 'amount_minor' => 10000, 'goes_to' => 'agency']],
            'tenant' => ['name' => 'Asha', 'phone' => '0701111111', 'id_number' => 'CF90SECRET789Z']]);
        $l2 = $this->tenantIn($owner, $units[1]->fresh(), '2026-03-01', ['deposit_minor' => 0, 'end_date' => '2026-04-30', 'tenant' => ['name' => 'Ben', 'phone' => '0702222222', 'id_number' => 'CM80SECRET123B']]);
        app(Payments::class)->receive($owner, $l1, ['amount_minor' => 610000, 'method' => 'cash']);
        $landlord = RentalLandlord::findOrFail($p->landlord_id);

        Carbon::setTestNow('2026-03-10 10:00:00');
        $ticket = app(Maintenance::class)->open($owner, $cid, ['unit_id' => $units[0]->id, 'title' => 'Roof']);
        app(Maintenance::class)->complete($owner, $ticket, ['cost_minor' => 20000, 'method' => 'cash', 'charged_to' => 'landlord', 'paid_on' => '2026-03-10']);
        Carbon::setTestNow('2026-03-15 10:00:00');
        app(Expenses::class)->record($owner, ['category' => 'Agency office', 'amount_minor' => 5000, 'method' => 'cash', 'charged_to' => 'agency']);
        Carbon::setTestNow('2026-03-20 10:00:00');
        app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 100000, 'method' => 'cash']);
        // 23:30 on the last day in Kampala is still 20:30 UTC.
        Carbon::setTestNow('2026-03-31 20:30:00');
        app(Payments::class)->receive($owner, $l2, ['amount_minor' => 50000, 'method' => 'mobile_money']);
        $open = app(Maintenance::class)->open($owner, $cid, ['unit_id' => $units[1]->id, 'title' => 'Tap']);

        return (object) compact('company', 'cid', 'owner', 'p', 'units', 'l1', 'l2', 'landlord', 'ticket', 'open');
    }

    private function run_(object $b, string $key, array $params = [], ?User $by = null): array
    {
        return (new RentalReports())->run($key, $b->cid, $by ?? $b->owner, $params);
    }

    private const MARCH = ['from' => '2026-03-01', 'to' => '2026-03-31'];

    public function test_the_journal_is_what_the_hand_says_it_is(): void
    {
        $b = $this->books();
        $t = RentalLedger::totals($b->cid);
        $this->assertSame([535000, 195000, 300000, 0, 45000, 5000, 0], [$t['cash_minor'], $t['landlord_minor'], $t['deposit_minor'], $t['credit_minor'], $t['income_minor'], $t['expense_minor'], $t['equity_minor']]);
        $this->assertSame(485000, RentalLedger::cash($b->cid)['cash']);
        $this->assertSame(50000, RentalLedger::cash($b->cid)['mobile_money']);
        $this->assertBooksAgree($b->cid);
    }

    public function test_rent_roll(): void
    {
        $b = $this->books();
        $r = $this->run_($b, 'rent_roll');
        $this->assertSame(['A1', 'A2', 'A3'], array_column($r['rows'], 'unit'));
        [$a1, $a2, $a3] = $r['rows'];
        $this->assertSame(['Asha', 300000, 300000, 300000, '2026-03-31', 0, 0, 'Occupied'], [$a1['tenant'], $a1['asking_minor'], $a1['rent_minor'], $a1['deposit_held_minor'], $a1['paid_up_to'], $a1['owed_minor'], $a1['overdue_minor'], $a1['state']]);
        $this->assertSame(['Ben', 200000, 0, 150000, 150000, 'Overdue'], [$a2['tenant'], $a2['rent_minor'], $a2['deposit_held_minor'], $a2['owed_minor'], $a2['overdue_minor'], $a2['state']]);
        $this->assertSame([null, 100000, null, null, 'Vacant'], [$a3['tenant'], $a3['asking_minor'], $a3['rent_minor'], $a3['owed_minor'], $a3['state']]);
        $this->assertSame(['rent_minor' => 500000, 'deposit_held_minor' => 300000, 'owed_minor' => 150000, 'overdue_minor' => 150000, 'units' => 3, 'occupied' => 2, 'vacant' => 1], $r['totals']);
        $this->assertSame([], $this->run_($b, 'rent_roll', ['landlord_id' => RentalLandlord::create(['company_id' => $b->cid, 'name' => 'Other'])->id])['rows']);
        $this->assertBooksAgree($b->cid);
    }

    public function test_collections_by_day_property_method_and_collector(): void
    {
        $b = $this->books();
        $r = $this->run_($b, 'collections', self::MARCH);
        $this->assertSame(['2026-03-01', '2026-03-31'], array_column($r['rows'], 'date'));
        $this->assertSame([610000, 0, 610000], [$r['rows'][0]['cash_minor'], $r['rows'][0]['mobile_money_minor'], $r['rows'][0]['total_minor']]);
        $this->assertSame([0, 50000, 50000], [$r['rows'][1]['cash_minor'], $r['rows'][1]['mobile_money_minor'], $r['rows'][1]['total_minor']]);
        $this->assertSame(['receipts' => 2, 'cash_minor' => 610000, 'bank_minor' => 0, 'mobile_money_minor' => 50000, 'cheque_minor' => 0, 'total_minor' => 660000],
            array_intersect_key($r['totals'], array_flip(['receipts', 'cash_minor', 'bank_minor', 'mobile_money_minor', 'cheque_minor', 'total_minor'])) + ['receipts' => 0]);
        [$byProperty, $byMethod, $byCollector] = $r['sections'];
        $this->assertSame(660000, $byProperty['rows'][0]['total_minor']);
        $this->assertSame([['Cash', 610000], ['Mobile money', 50000]], array_map(fn ($x) => [$x['name'], $x['total_minor']], $byMethod['rows']));
        $this->assertSame([$b->owner->name, 2, 660000], [$byCollector['rows'][0]['name'], $byCollector['rows'][0]['receipts'], $byCollector['rows'][0]['total_minor']]);

        // A reversal comes off on the day it was made.
        Carbon::setTestNow('2026-04-02 09:00:00');
        $p = \App\Models\RentalPayment::where('lease_id', $b->l2->id)->firstOrFail();
        app(Payments::class)->reverse($b->owner, $p, 'Bounced');
        $this->assertSame(660000, $this->run_($b, 'collections', self::MARCH)['totals']['total_minor'], 'March as printed stays');
        $this->assertSame(-50000, $this->run_($b, 'collections', ['from' => '2026-04-01', 'to' => '2026-04-30'])['totals']['total_minor']);
        $this->assertBooksAgree($b->cid);
    }

    public function test_arrears_aging_buckets(): void
    {
        $b = $this->books();
        $r = $this->run_($b, 'arrears_aging');
        $this->assertCount(1, $r['rows']);
        $this->assertSame(['Ben', '0702222222', 'A2', 30, 150000, 0, 0, 0, 150000], [$r['rows'][0]['tenant'], $r['rows'][0]['phone'], $r['rows'][0]['unit'], $r['rows'][0]['days'], $r['rows'][0]['d0_30_minor'], $r['rows'][0]['d31_60_minor'],
            $r['rows'][0]['d61_90_minor'], $r['rows'][0]['d90_plus_minor'], $r['rows'][0]['total_minor']]);
        $this->assertSame(150000, $r['totals']['total_minor']);

        // Thirty days later the bill is 31 days overdue and moves a bucket; the buckets and the overview agree.
        Carbon::setTestNow('2026-04-01 09:00:00');
        $r = $this->run_($b, 'arrears_aging');
        $this->assertSame([0, 150000, 150000], [$r['rows'][0]['d0_30_minor'], $r['rows'][0]['d31_60_minor'], $r['totals']['total_minor']]);
        $this->assertBooksAgree($b->cid);
    }

    public function test_occupancy_and_rent_lost(): void
    {
        $b = $this->books();
        $r = $this->run_($b, 'occupancy', self::MARCH);
        // A3 stood empty all 31 days of March: its whole month's asking rent.
        $this->assertSame(['units' => 3, 'occupied' => 2, 'vacant' => 1, 'occupancy_bp' => 6667, 'vacancy_days' => 31, 'rent_lost_minor' => 100000], array_intersect_key($r['rows'][0], array_flip(['units', 'occupied', 'vacant', 'occupancy_bp', 'vacancy_days', 'rent_lost_minor'])));
        $this->assertSame(6667, $r['totals']['occupancy_bp']);
        // Part of a month: 10 days of 31 at 100,000 = 32,258 (half up).
        $this->assertSame(32258, $this->run_($b, 'occupancy', ['from' => '2026-03-22', 'to' => '2026-03-31'])['rows'][0]['rent_lost_minor']);
        $this->assertBooksAgree($b->cid);
    }

    public function test_landlord_statement_reconciles_to_the_journal_on_the_cash_basis(): void
    {
        $b = $this->books();
        $r = $this->run_($b, 'landlord_statement', self::MARCH + ['landlord_id' => $b->landlord->id]);
        $this->assertEquals(['opening_minor' => 0, 'collected_minor' => 350000, 'commission_minor' => 35000, 'expenses_minor' => 20000, 'payouts_minor' => 100000, 'deposits_minor' => 0, 'other_minor' => 0, 'closing_minor' => 195000],
            array_intersect_key($r['totals'], array_flip(['opening_minor', 'collected_minor', 'commission_minor', 'expenses_minor', 'payouts_minor', 'deposits_minor', 'other_minor', 'closing_minor'])));
        // Billed but not collected is not the landlord's money: 200,000 rent billed on A2, only 45,000 reached them.
        $journal = (int) RentalEntry_sum($b->cid, $b->landlord->id, '2026-03-31');
        $this->assertSame($journal, $r['totals']['closing_minor']);
        $this->assertSame(LandlordAccounts::balance($b->landlord->fresh(), '2026-03-31'), $r['totals']['closing_minor']);
        $last = end($r['rows']);
        $this->assertSame(195000, $last['balance_minor'], 'the running balance ends on the closing figure');
        $this->assertSame($r['totals']['closing_minor'], $r['totals']['opening_minor'] + $r['totals']['collected_minor'] - $r['totals']['commission_minor'] - $r['totals']['expenses_minor'] - $r['totals']['payouts_minor'] + $r['totals']['deposits_minor'] + $r['totals']['other_minor']);
        $this->assertSame([['unit' => 'A2', 'tenant' => 'Ben', 'owed_minor' => 150000, 'days' => 30]], $r['sections'][0]['rows']);
        $this->assertEquals(['units' => 3, 'occupied' => 2, 'vacant' => 1], array_intersect_key($r['totals'], array_flip(['units', 'occupied', 'vacant'])));
        // Two periods chain: April opens on March's close, and printing twice gives the same paper.
        $april = $this->run_($b, 'landlord_statement', ['from' => '2026-04-01', 'to' => '2026-04-30', 'landlord_id' => $b->landlord->id]);
        $this->assertSame(195000, $april['totals']['opening_minor']);
        $this->assertEquals(array_diff_key($r, ['generated_at' => 1]), array_diff_key($this->run_($b, 'landlord_statement', self::MARCH + ['landlord_id' => $b->landlord->id]), ['generated_at' => 1]));
        // Asking without a landlord.
        $this->refused('landlord_required', fn () => $this->run_($b, 'landlord_statement', self::MARCH));
        $this->assertBooksAgree($b->cid);
    }

    public function test_commission_expenses_deposits_held_and_cash_book(): void
    {
        $b = $this->books();
        $c = $this->run_($b, 'commission', self::MARCH);
        $this->assertSame([350000, 35000, 1000], [$c['rows'][0]['gross_minor'], $c['rows'][0]['commission_minor'], $c['rows'][0]['rate_bp']]);
        $this->assertSame(['gross_minor' => 350000, 'commission_minor' => 35000, 'rate_bp' => 1000], $c['totals']);

        $e = $this->run_($b, 'expenses', self::MARCH);
        $this->assertSame([20000, 5000], array_column($e['rows'], 'amount_minor'));
        $this->assertSame(['amount_minor' => 25000, 'landlords_minor' => 20000, 'agency_minor' => 5000], $e['totals']);
        $this->assertSame([['Agency office', 1, 5000], ['Repairs', 1, 20000]], array_map(fn ($x) => [$x['category'], $x['count'], $x['amount_minor']], $e['sections'][0]['rows']));
        $this->assertSame([$b->landlord->name, 'Agency'], array_column($e['rows'], 'charged_to'));

        $d = $this->run_($b, 'deposits_held');
        $this->assertCount(1, $d['rows']);
        $this->assertSame(['Asha', 'Agency', 300000, 300000], [$d['rows'][0]['tenant'], $d['rows'][0]['held_by'], $d['rows'][0]['deposit_minor'], $d['rows'][0]['held_minor']]);
        $this->assertSame(['deposit_minor' => 300000, 'held_minor' => 300000, 'held_by_agency_minor' => 300000, 'held_by_landlords_minor' => 0, 'leases' => 1], $d['totals']);
        $this->assertSame((int) RentalLedger::totals($b->cid)['deposit_minor'], $d['totals']['held_by_agency_minor'], 'the register is the journal');

        $k = $this->run_($b, 'cash_book', self::MARCH);
        $this->assertSame(['in_minor' => 660000, 'out_minor' => 125000, 'balance_minor' => 535000, 'opening_minor' => 0, 'closing_minor' => 535000], $k['totals']);
        $this->assertSame(535000, end($k['rows'])['balance_minor']);
        $this->assertSame([['Cash', 0, 610000, 125000, 485000], ['Mobile money', 0, 50000, 0, 50000]], array_map(fn ($x) => [$x['method'], $x['opening_minor'], $x['in_minor'], $x['out_minor'], $x['closing_minor']], $k['sections'][0]['rows']));
        $this->assertSame(50000, $this->run_($b, 'cash_book', self::MARCH + ['method' => 'mobile_money'])['totals']['closing_minor']);
        $this->assertSame(['opening_minor' => 610000, 'closing_minor' => 485000], array_intersect_key($this->run_($b, 'cash_book', ['from' => '2026-03-02', 'to' => '2026-03-30'])['totals'], array_flip(['opening_minor', 'closing_minor'])));
        $this->assertBooksAgree($b->cid);
    }

    public function test_tenant_statement_lease_expiries_maintenance_and_income_statement(): void
    {
        $b = $this->books();
        $ten = $this->run_($b, 'tenant_statement', self::MARCH + ['lease_id' => $b->l2->id]);
        $this->assertSame(['charged_minor' => 200000, 'paid_minor' => 50000, 'balance_minor' => 150000, 'opening_minor' => 0, 'closing_minor' => 150000], $ten['totals']);
        $this->assertSame(150000, end($ten['rows'])['balance_minor']);
        $one = $this->run_($b, 'tenant_statement', self::MARCH + ['lease_id' => $b->l1->id]);
        $this->assertSame(['charged_minor' => 610000, 'paid_minor' => 610000, 'balance_minor' => 0], array_intersect_key($one['totals'], array_flip(['charged_minor', 'paid_minor', 'balance_minor'])));
        $this->assertSame([['Paid in', 300000], ['Used for what was owed at move-out', 0], ['Refunded', 0], ['Held now', 300000]], array_map(fn ($x) => [$x['line'], $x['amount_minor']], $one['sections'][0]['rows']));
        $this->assertSame(0, $this->run_($b, 'tenant_statement', ['from' => '2026-03-02', 'to' => '2026-03-31', 'lease_id' => $b->l1->id])['totals']['opening_minor'], 'the opening balance is what was billed less paid before the period');
        $this->refused('lease_required', fn () => $this->run_($b, 'tenant_statement', self::MARCH));

        $x = $this->run_($b, 'lease_expiries');
        $this->assertSame(1, $x['totals']['leases']);
        $this->assertSame(['2026-04-30', 30, 'Ben', 200000, 150000], [$x['rows'][0]['ends_on'], $x['rows'][0]['days_left'], $x['rows'][0]['tenant'], $x['rows'][0]['rent_minor'], $x['rows'][0]['owed_minor']]);
        $this->assertSame([], $this->run_($b, 'lease_expiries', ['days' => 10])['rows']);
        app(Leases::class)->notice($b->owner, $b->l1->fresh(), null, '2026-04-10');
        $this->assertSame(['2026-04-10', '2026-04-30'], array_column($this->run_($b, 'lease_expiries')['rows'], 'ends_on'));

        $m = $this->run_($b, 'maintenance', self::MARCH);
        $this->assertSame(['cost_minor' => 20000, 'requests' => 2, 'still_open' => 1], $m['totals']);
        $this->assertSame([['Open', 1, 0], ['Done', 1, 20000]], array_map(fn ($s) => [$s['status'], $s['requests'], $s['cost_minor']], $m['sections'][0]['rows']));
        $this->assertSame(0, array_sum(array_column(array_filter($m['rows'], fn ($r) => $r['status'] === 'Open'), 'cost_minor')));

        $i = $this->run_($b, 'income_statement', self::MARCH);
        $this->assertSame(['amount_minor' => 40000, 'income_minor' => 45000, 'expenses_minor' => 5000, 'net_minor' => 40000], $i['totals']);
        $this->assertSame(35000, array_sum(array_map(fn ($r) => str_starts_with($r['line'], 'Commission') ? $r['amount_minor'] : 0, $i['rows'])));
        $this->assertSame(10000, array_sum(array_map(fn ($r) => str_starts_with($r['line'], 'Fees kept') ? $r['amount_minor'] : 0, $i['rows'])));
        $t = RentalLedger::totals($b->cid);
        $this->assertSame($t['income_minor'] - $t['expense_minor'], $i['totals']['net_minor'], 'the income statement is the journal');
        $this->assertBooksAgree($b->cid);
    }

    /** The benchmark's old system dropped 19 month-end payments: a period holds the whole of its last day. */
    public function test_a_report_period_holds_the_whole_of_its_last_day(): void
    {
        $b = $this->books();
        // Recorded at 23:30 Kampala time on 31 March: in March, in every report that reads money or requests.
        $this->assertSame(660000, $this->run_($b, 'collections', self::MARCH)['totals']['total_minor']);
        $this->assertSame(610000, $this->run_($b, 'collections', ['from' => '2026-03-01', 'to' => '2026-03-30'])['totals']['total_minor']);
        $this->assertSame(535000, $this->run_($b, 'cash_book', self::MARCH)['totals']['closing_minor']);
        $this->assertSame(350000, $this->run_($b, 'commission', self::MARCH)['totals']['gross_minor']);
        $this->assertSame(195000, $this->run_($b, 'landlord_statement', self::MARCH + ['landlord_id' => $b->landlord->id])['totals']['closing_minor']);
        $this->assertSame(150000, $this->run_($b, 'tenant_statement', self::MARCH + ['lease_id' => $b->l2->id])['totals']['closing_minor']);
        $this->assertContains('Tap', array_column($this->run_($b, 'maintenance', ['from' => '2026-03-31', 'to' => '2026-03-31'])['rows'], 'title'));
        $this->assertSame(['Roof'], array_column($this->run_($b, 'maintenance', ['from' => '2026-03-01', 'to' => '2026-03-30'])['rows'], 'title'));

        // Half past midnight on 1 April in Kampala is still 31 March in UTC: it belongs to April.
        Carbon::setTestNow('2026-03-31 21:30:00');
        $late = app(Payments::class)->receive($b->owner, $b->l2->fresh(), ['amount_minor' => 1000, 'method' => 'cash']);
        $this->assertSame('2026-04-01', $late->paid_on->toDateString());
        $this->assertSame(660000, $this->run_($b, 'collections', self::MARCH)['totals']['total_minor']);
        $this->assertSame(1000, $this->run_($b, 'collections', ['from' => '2026-04-01', 'to' => '2026-04-30'])['totals']['total_minor']);
        // And 23:59:59 on a 31st, and a one-day period.
        Carbon::setTestNow('2026-03-31 20:59:59');
        $lastSecond = app(Payments::class)->receive($b->owner, $b->l2->fresh(), ['amount_minor' => 2000, 'method' => 'cash']);
        $this->assertSame('2026-03-31', $lastSecond->paid_on->toDateString());
        $this->assertSame(52000, $this->run_($b, 'collections', ['from' => '2026-03-31', 'to' => '2026-03-31'])['totals']['total_minor']);
        $this->assertBooksAgree($b->cid);
    }

    public function test_a_deposit_is_never_income_in_any_report(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        foreach (['agency' => 'agency', 'landlord holds it' => 'landlord', 'owner mode' => null] as $how => $heldBy) {
            [$company, $owner] = $this->rentalsAccount($heldBy === null ? 'owner' : 'agency');
            $cid = (int) $company->id;
            [$p, $units] = $this->building($owner, 1, 300000);
            $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 200000] + ($heldBy ? ['deposit_held_by' => $heldBy] : []));
            // Only the deposit is paid: not a shilling of it is income.
            app(Payments::class)->receive($owner, $lease, ['amount_minor' => 200000, 'method' => 'cash']);
            $r = (new RentalReports())->run('income_statement', $cid, $owner, self::MARCH);
            $this->assertSame([0, 0], [$r['totals']['income_minor'], $r['totals']['net_minor']], $how);
            $this->assertSame([], array_values(array_filter($r['rows'], fn ($row) => stripos($row['line'], 'deposit') !== false)), $how);
            $this->assertSame(0, (int) RentalLedger::totals($cid)['income_minor'], $how);
            if ($heldBy !== null) {
                $this->assertSame(0, (new RentalReports())->run('commission', $cid, $owner, self::MARCH)['totals']['commission_minor'], $how);
                $st = LandlordAccounts::statement(RentalLandlord::findOrFail($p->landlord_id), '2026-03-01', '2026-03-31');
                $this->assertSame([0, 0], [$st['totals']['collected_minor'], $st['totals']['commission_minor']], $how.': not rent collected');
                $this->assertSame($heldBy === 'landlord' ? 200000 : 0, $st['totals']['deposits_minor'], $how);
            }
            // Refunded later: still no income in either direction.
            Carbon::setTestNow('2026-03-02 09:00:00');
            app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-01', 'refund_deposit' => 'cash']);
            $this->assertSame(0, (new RentalReports())->run('income_statement', $cid, $owner, self::MARCH)['totals']['net_minor'], $how);
            $this->assertBooksAgree($cid);
            Carbon::setTestNow('2026-03-01 09:00:00');
        }
    }

    public function test_occupancy_is_always_from_the_live_leases_never_what_was_last_saved(): void
    {
        $b = $this->books();
        $h = $this->auth($b->owner->createToken('t')->plainTextToken);
        $occupied = function () use ($b, $h) {
            $rows = $this->run_($b, 'occupancy', self::MARCH)['totals']['occupied'];
            $roll = $this->run_($b, 'rent_roll')['totals']['occupied'];
            $overview = $this->getJson('/api/v1/rentals/overview', $h)->assertOk()->json('data.occupied');
            $property = $this->getJson('/api/v1/rentals/properties/'.$b->p->id, $h)->assertOk()->json('data.occupied_count');
            $this->assertSame([$rows, $rows, $rows], [$roll, $overview, $property]);

            return $rows;
        };
        $this->assertSame(2, $occupied());
        // There is no such status to save; the saved status never makes a unit full or empty.
        $this->putJson('/api/v1/rentals/units/'.$b->units[2]->id, ['status' => 'occupied'], $h)->assertStatus(422);
        $this->putJson('/api/v1/rentals/units/'.$b->units[0]->id, ['status' => 'available', 'notes' => 'Saved again'], $h)->assertOk()->assertJsonPath('data.state', 'occupied');
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('rental_units', 'occupied'));
        DB::table('rental_units')->where('id', $b->units[2]->id)->update(['status' => 'reserved']);
        $this->assertSame(2, $occupied());
        $this->assertSame('reserved', $this->getJson('/api/v1/rentals/units/'.$b->units[2]->id, $h)->json('data.state'));
        DB::table('rental_units')->where('id', $b->units[2]->id)->update(['status' => 'available']);
        // The lease ends: it empties at once, with no save on the unit.
        $updated = DB::table('rental_units')->where('id', $b->units[0]->id)->value('updated_at');
        Carbon::setTestNow('2026-04-01 09:00:00');
        app(Leases::class)->moveOut($b->owner, $b->l1->fresh(), ['date' => '2026-04-01']);
        $this->assertSame($updated, DB::table('rental_units')->where('id', $b->units[0]->id)->value('updated_at'), 'the move-out never wrote to the unit');
        $this->assertSame(1, $this->getJson('/api/v1/rentals/overview', $this->auth($b->owner->createToken('t')->plainTextToken))->json('data.occupied'));
        $this->assertSame(1, $this->run_($b, 'rent_roll')['totals']['occupied']);
        $this->assertSame('vacant', $this->getJson('/api/v1/rentals/units/'.$b->units[0]->id, $this->auth($b->owner->createToken('t')->plainTextToken))->json('data.state'));
        // March stays what it was.
        $this->assertSame(['units' => 3, 'occupied' => 2, 'vacant' => 1], LandlordAccounts::occupancy($b->landlord, '2026-03-31'));
        $this->assertSame(['units' => 3, 'occupied' => 1, 'vacant' => 2], LandlordAccounts::occupancy($b->landlord));
        $this->assertBooksAgree($b->cid);
    }

    public function test_every_report_opens_as_csv_xlsx_and_pdf_and_carries_no_id_number(): void
    {
        $b = $this->books();
        $h = $this->auth($b->owner->createToken('t')->plainTextToken);
        foreach (RentalReports::keys() as $key) {
            $query = http_build_query(self::MARCH + ['landlord_id' => $b->landlord->id, 'lease_id' => $b->l1->id, 'property_id' => $b->p->id]);
            $json = $this->getJson("/api/v1/rentals/reports/{$key}?{$query}", $h)->assertOk()->json('data');
            $this->assertSame(['key', 'title', 'subtitle', 'columns', 'rows', 'totals', 'sections', 'currency', 'decimals', 'from', 'to', 'account', 'generated_at'], array_slice(array_keys($json), 0, 13), $key);
            $this->assertSame('UGX', $json['currency']);
            foreach (['csv', 'xlsx', 'pdf'] as $format) {
                $link = $this->getJson("/api/v1/rentals/reports/{$key}?{$query}&format={$format}", $h)->assertOk()->json('data');
                $this->assertSame(1800, $link['expires_in']);
                $file = $this->get($link['url'])->assertOk();
                $bytes = (string) $file->getContent();
                if ($format === 'csv') {
                    $this->assertStringStartsWith("\xEF\xBB\xBF", $bytes, $key);
                    $this->assertStringContainsString((string) Company::withoutGlobalScopes()->find($b->cid)->name, $bytes);
                    $this->assertStringNotContainsString('SECRET', $bytes, $key);
                } elseif ($format === 'xlsx') {
                    $path = tempnam(sys_get_temp_dir(), 'kr');
                    file_put_contents($path, $bytes);
                    $zip = new \ZipArchive();
                    $this->assertTrue($zip->open($path) === true, $key);
                    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
                    $this->assertNotSame('', $sheet, $key);
                    $this->assertNotFalse(simplexml_load_string($sheet), $key.' is well formed');
                    $this->assertStringNotContainsString('SECRET', $sheet);
                    $zip->close();
                    unlink($path);
                } else {
                    $this->assertStringStartsWith('%PDF', $bytes, $key);
                }
            }
        }
        // Figures in the file are the report's: CSV in plain major units.
        $csv = $this->get($this->getJson('/api/v1/rentals/reports/cash_book?from=2026-03-01&to=2026-03-31&format=csv', $h)->json('data.url'))->getContent();
        $this->assertStringContainsString('535000', (string) $csv);
        $this->getJson('/api/v1/rentals/reports/nope', $h)->assertStatus(404);
        $this->getJson('/api/v1/rentals/reports/rent_roll?format=docx', $h)->assertStatus(422)->assertJsonPath('errors.code', 'unknown_format');
        $this->getJson('/api/v1/rentals/reports/collections?from=banana', $h)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_date');
        $this->getJson('/api/v1/rentals/reports/collections?from=2026-03-31&to=2026-03-01', $h)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_dates');
        $this->assertBooksAgree($b->cid);
    }

    public function test_a_formula_in_a_name_is_neutralised_and_cents_stay_exact(): void
    {
        $b = $this->books();
        RentalTenant::where('id', $b->l2->tenant_id)->update(['name' => '=HYPERLINK("http://evil","x")']);
        RentalTenant::where('id', $b->l1->tenant_id)->update(['name' => '@SUM(1+1)']);
        DB::table('rental_properties')->where('id', $b->p->id)->update(['name' => '+cmd|calc']);
        DB::table('companies')->where('id', $b->cid)->update(['name' => '-1+1 Agency']);
        $r = $this->run_($b, 'rent_roll');
        $rows = array_map(fn ($line) => str_getcsv($line), explode("\n", trim(substr(RentalExport::csv($r, $b->cid), 3))));
        $cells = array_map('strval', array_merge(...$rows));
        foreach ($cells as $cell) {
            if ($cell !== '' && ! is_numeric($cell)) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, $cell);
            }
        }
        $this->assertContains('\'=HYPERLINK("http://evil","x")', $cells);
        $this->assertContains("'@SUM(1+1)", $cells);
        $this->assertContains("'+cmd|calc", $cells);
        $this->assertContains("'-1+1 Agency", $cells);
        // A real number is left a number, a phone too.
        $this->assertSame('-5000', RentalExport::safe('-5000'));
        $this->assertSame('+256701111111', RentalExport::safe('+256701111111'));
        $this->assertSame("'\t=1+1", RentalExport::safe("\t=1+1"));
        // XLSX cells are inline text, never formulas; PDF escapes.
        $xlsx = RentalExport::xlsx($r, $b->cid);
        $path = tempnam(sys_get_temp_dir(), 'kr');
        file_put_contents($path, $xlsx);
        $zip = new \ZipArchive();
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('HYPERLINK', $sheet);

        // A currency with cents: exact strings in CSV and PDF money.
        $this->assertSame('1250000.50', \App\Support\Rentals\Minor::toMajor(125000050, 2));
        $this->assertSame('-25,000.50', RentalExport::money(-2500050, 2));
        $this->assertSame('1,250,000', RentalExport::money(1250000, 0));
        $this->assertBooksAgree($b->cid);
    }

    public function test_a_caretaker_sees_their_properties_rows_and_never_the_accounts_money(): void
    {
        $b = $this->books();
        [$q, $others] = $this->building($b->owner, 1, 900000);
        $outside = $this->tenantIn($b->owner, $others[0], '2026-03-31', ['deposit_minor' => 0, 'tenant' => ['name' => 'Outsider', 'phone' => '0703333333']]);
        app(Payments::class)->receive($b->owner, $outside, ['amount_minor' => 900000, 'method' => 'bank']);
        $caretaker = $this->rentalsStaff($b->company, 'rentals_caretaker');
        app(Properties::class)->assignStaff($b->owner, $b->p, [$caretaker->id]);
        // By the role table a caretaker has no reports (§7): the API and the service both say so.
        $h = $this->auth($caretaker->createToken('t')->plainTextToken);
        $this->getJson('/api/v1/rentals/reports/rent_roll', $h)->assertStatus(403)->assertJsonPath('errors.permission', 'rentals_reports');
        $this->refused('forbidden', fn () => $this->run_($b, 'rent_roll', [], $caretaker));

        // Should a role ever be given reports (§17 says each report respects the scope), the scope holds.
        $roleWith = array_merge((array) config('permissions.roles.rentals_caretaker.permissions'), ['rentals_reports']);
        config(['permissions.roles.rentals_caretaker.permissions' => $roleWith]);
        $withReports = $this->rentalsStaff($b->company, 'rentals_caretaker');
        app(Properties::class)->assignStaff($b->owner, $b->p, [$caretaker->id, $withReports->id]);
        $this->assertTrue(\App\Services\Team\Permissions::can($withReports, 'rentals_reports'));
        $this->assertSame(['A1', 'A2', 'A3'], array_column($this->run_($b, 'rent_roll', [], $withReports)['rows'], 'unit'));
        $this->assertSame(660000, $this->run_($b, 'collections', self::MARCH, $withReports)['totals']['total_minor']);
        $this->assertSame(25000 - 5000, $this->run_($b, 'expenses', self::MARCH, $withReports)['totals']['amount_minor'], 'the agency\'s own costs have no property: not theirs');
        foreach (['arrears_aging', 'occupancy', 'deposits_held', 'lease_expiries', 'maintenance'] as $key) {
            $this->assertStringNotContainsString('Outsider', (string) json_encode($this->run_($b, $key, self::MARCH, $withReports)['rows']), $key);
        }
        foreach (['landlord_statement', 'commission', 'cash_book', 'income_statement'] as $key) {
            $this->refused('forbidden', fn () => $this->run_($b, $key, self::MARCH + ['landlord_id' => $b->landlord->id], $withReports));
        }
        $wh = $this->auth($withReports->createToken('t')->plainTextToken);
        $this->getJson('/api/v1/rentals/reports/cash_book?format=csv', $wh)->assertStatus(403);
        $this->refused('not_found', fn () => $this->run_($b, 'tenant_statement', self::MARCH + ['lease_id' => $outside->id], $withReports));
        $this->refused('not_found', fn () => $this->run_($b, 'rent_roll', ['property_id' => $q->id], $withReports));
        $this->assertStringNotContainsString('Outsider', (string) $this->get($this->getJson('/api/v1/rentals/reports/rent_roll?format=csv', $wh)->assertOk()->json('data.url'))->assertOk()->getContent());

        // The owner sees all; a stranger's run of this account's report finds nothing.
        $this->assertSame(1560000, $this->run_($b, 'collections', self::MARCH)['totals']['total_minor']);
        [, $stranger] = $this->rentalsAccount();
        $this->refused('not_found', fn () => (new RentalReports())->run('rent_roll', $b->cid, $stranger));
        // A signed report link runs again for the person who asked: taken away, it opens nothing.
        $ownerH = $this->auth($b->owner->createToken('t')->plainTextToken);
        $url = $this->getJson('/api/v1/rentals/reports/rent_roll?format=csv', $ownerH)->json('data.url');
        $this->get($url)->assertOk();
        $this->get(str_replace('format=csv', 'format=pdf', $url))->assertStatus(403);
        Carbon::setTestNow(now()->addMinutes(31));
        $this->get($url)->assertStatus(403);
        $this->assertBooksAgree($b->cid);
    }

    public function test_an_owner_account_reads_rent_less_expenses(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner');
        $cid = (int) $company->id;
        [$p, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 100000]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 400000, 'method' => 'cash']);
        app(Expenses::class)->record($owner, ['category' => 'Repairs', 'amount_minor' => 30000, 'method' => 'cash', 'property_id' => $p->id]);
        app(Payouts::class)->record($owner, RentalsModule::selfLandlord($cid), ['amount_minor' => 50000, 'method' => 'cash']);
        $r = (new RentalReports())->run('income_statement', $cid, $owner, self::MARCH);
        $this->assertSame(['income_minor' => 300000, 'expenses_minor' => 30000, 'net_minor' => 270000, 'drawings_minor' => 50000], array_intersect_key($r['totals'], array_flip(['income_minor', 'expenses_minor', 'net_minor', 'drawings_minor'])));
        $this->refused('agency_only', fn () => (new RentalReports())->run('commission', $cid, $owner, self::MARCH));
        $this->assertNotContains('commission', array_column(RentalReports::registry($cid), 'key'));
        $st = (new RentalReports())->run('landlord_statement', $cid, $owner, self::MARCH);
        $this->assertSame('Property income statement', $st['title']);
        $this->assertSame(220000, $st['totals']['closing_minor'], '300,000 rent − 30,000 repairs − 50,000 drawn');
        $this->assertBooksAgree($cid);
    }

    private function refused(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());

            return;
        }
        $this->fail("Expected refusal {$code}");
    }
}

/** The journal's own figure for a landlord, without any service in the way. */
function RentalEntry_sum(int $companyId, int $landlordId, string $asOf): int
{
    return (int) DB::table('rental_entries')->where('company_id', $companyId)->where('landlord_id', $landlordId)->where('occurred_on', '<=', $asOf)->sum('landlord_minor');
}

<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\RentalSetting;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Jobs\RentalCheck;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\MonthEndFigures;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Landlords\UnitSheet;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Payments\Receipt;
use App\Services\Rentals\Reports\MoneyPosition;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * A tenant who paid the landlord at the gate (LC-20): the bill is settled, no money comes into the agency's accounts, and the landlord's balance falls by the
 * commission. Invented figures: rent 500,000 at 10%. Every test ends with rentals:check finding nothing.
 */
class RentalsDirectPaymentTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function refused(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected refusal {$code}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }

    /** A lease on rent 500,000 at 10% with November's rent open and no deposit. @return array<string, mixed> */
    private function open(array $lease = [], array $settings = []): array
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', $settings + ['commission_type' => 'percent', 'commission_bp' => 1000]);
        [$p, $units] = $this->building($owner, 2, 500000);
        $l = $this->tenantIn($owner, $units[0], '2026-11-01', $lease + ['deposit_minor' => 0]);

        return ['company' => $company, 'owner' => $owner, 'cid' => (int) $company->id, 'p' => $p, 'units' => $units, 'lease' => $l->fresh(),
            'landlord' => RentalLandlord::withoutGlobalScopes()->findOrFail($l->landlord_id), 'bill' => RentalCharge::where('lease_id', $l->id)->where('kind', 'rent')->firstOrFail()];
    }

    public function test_the_bill_is_settled_no_cash_moves_and_the_landlord_loses_the_commission(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord, 'bill' => $bill] = $this->open();
        $before = RentalLedger::cash($cid);
        $position = MoneyPosition::asOf($cid, '2026-11-05');

        $p = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000, 'paid_on' => '2026-11-05', 'reference' => 'Gate']);

        $this->assertSame(RentalPayment::DIRECT, $p->method);
        $this->assertSame(0, (int) $p->unallocated_minor);
        $this->assertFalse($p->movesCash());
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(500000, (int) $bill->fresh()->paid_minor);
        $a = RentalAllocation::where('payment_id', $p->id)->firstOrFail();
        $this->assertSame([500000, 50000, 450000, 0, 0], [(int) $a->amount_minor, (int) $a->commission_minor, (int) $a->landlord_minor, (int) $a->agency_minor, (int) $a->deposit_minor]);

        $rows = RentalEntry::where('payment_id', $p->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('collected_direct', $rows[0]->type);
        $this->assertSame([0, -50000, 50000, 0, 0, null], [(int) $rows[0]->cash_minor, (int) $rows[0]->landlord_minor, (int) $rows[0]->income_minor, (int) $rows[0]->credit_minor, (int) $rows[0]->deposit_minor, $rows[0]->method]);

        // The position's identity: cash unchanged, the landlord owes the agency, and the agency's own money counts it.
        $this->assertSame($before, RentalLedger::cash($cid));
        $this->assertSame(-50000, LandlordAccounts::balance($landlord));
        $after = MoneyPosition::asOf($cid, '2026-11-05');
        $this->assertSame($position['cash_minor'], $after['cash_minor']);
        $this->assertSame(50000, $after['owed_back_by_landlords_minor']);
        $this->assertSame($after['cash_minor'], $after['owed_to_landlords_minor'] - $after['owed_back_by_landlords_minor'] + $after['deposits_minor'] + $after['credit_minor'] + $after['agency_own_minor']);

        // Arrears clear, and the lease reads as paid up.
        $pos = Leases::positions([(int) $lease->id])[(int) $lease->id];
        $this->assertSame(0, (int) $pos['owed_minor']);
        $this->assertSame('2026-11-30', $pos['paid_up_to']);
        $this->assertBooksAgree($cid);
    }

    public function test_the_statement_says_so_and_adds_up(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->open();
        app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000, 'paid_on' => '2026-11-05']);

        $s = LandlordAccounts::statement($landlord, '2026-11-01', '2026-11-30');
        $this->assertCount(1, $s['lines']);
        $line = $s['lines'][0];
        $this->assertSame('collected_direct', $line['type']);
        $this->assertStringContainsString('Tenant paid you directly: 500,000. Commission 50,000.', $line['description']);
        $this->assertSame([0, 50000, -50000, 500000], [$line['gross_minor'], $line['commission_minor'], $line['amount_minor'], $line['direct_minor']]);
        $this->assertSame(['collected' => 0, 'commission' => 50000, 'other' => 0, 'direct' => 500000], ['collected' => $s['totals']['collected_minor'], 'commission' => $s['totals']['commission_minor'],
            'other' => $s['totals']['other_minor'], 'direct' => $s['totals']['direct_minor']]);
        $this->assertSame(-50000, $s['closing_minor']);
        $this->assertSame($s['closing_minor'], $s['opening_minor'] + $s['totals']['collected_minor'] - $s['totals']['commission_minor'] + $s['totals']['deposits_minor'] - $s['totals']['expenses_minor'] - $s['totals']['payouts_minor'] + $s['totals']['other_minor']);
        $this->assertSame([], $s['arrears']);

        // Month end says what the statement says, line for line, with the rate on all the rent.
        $row = collect(MonthEndFigures::byLandlord($cid, '2026-11-01', '2026-11-30'))->firstWhere('landlord_id', (int) $landlord->id);
        $this->assertSame(50000, $row['commission_minor']);
        $this->assertSame(0, $row['collected_minor']);
        $this->assertSame(500000, $row['direct_minor']);
        $this->assertSame(1000, $row['rate_bp']);
        $this->assertSame(-50000, $row['closing_minor']);

        // The rent sheet: not "collected" (the column stays the statement's), but shown as paid to the landlord.
        $sheet = collect(UnitSheet::rows($landlord, '2026-11-30'))->firstWhere('lease_id', (int) $lease->id);
        $this->assertSame(0, $sheet['paid_minor']);
        $this->assertSame(500000, $sheet['direct_minor']);
        $this->assertSame(0, $sheet['owed_minor']);
        $this->assertSame('2026-11-05', $sheet['last_paid_on']);

        // The report and the paper's figures.
        $report = (new RentalReports)->run('landlord_statement', $cid, null, ['landlord_id' => (int) $landlord->id, 'from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(500000, $report['totals']['direct_minor']);
        $this->assertSame(0, $report['totals']['gross_minor']);
        $this->assertSame(-50000, $report['totals']['amount_minor']);
        $units = collect($report['sections'])->firstWhere('key', 'units');
        $this->assertContains('direct_minor', array_column($units['columns'], 'key'));
        $this->assertBooksAgree($cid);
    }

    public function test_no_commission_posts_no_row_and_the_statement_still_tells_the_landlord(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'landlord' => $landlord, 'units' => $units] = $this->open();
        $lease = $this->tenantIn($owner, $units[1], '2026-11-01', ['deposit_minor' => 0, 'commission' => ['type' => 'none']]);
        $p = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000, 'paid_on' => '2026-11-05']);

        $this->assertSame(0, RentalEntry::where('payment_id', $p->id)->count());
        $this->assertSame(0, LandlordAccounts::balance($landlord));
        $s = LandlordAccounts::statement($landlord, '2026-11-01', '2026-11-30');
        $line = collect($s['lines'])->firstWhere('type', 'collected_direct');
        $this->assertNotNull($line);
        $this->assertSame([0, 0, 0, 500000], [$line['gross_minor'], $line['commission_minor'], $line['amount_minor'], $line['direct_minor']]);
        $this->assertStringContainsString('Tenant paid you directly: 500,000.', $line['description']);
        $this->assertSame(500000, $s['totals']['direct_minor']);
        $this->assertSame(0, $s['closing_minor']);
        $this->assertBooksAgree($cid);

        // Its reversal posts nothing either, and the statement shows it taken back.
        app(Payments::class)->reverse($owner, $p, 'Typed on the wrong lease');
        $s = LandlordAccounts::statement($landlord, '2026-11-01', '2026-11-30');
        $this->assertSame(0, $s['totals']['direct_minor'] ?? 0);
        $this->assertSame(0, RentalEntry::where('payment_id', $p->id)->count());
        $this->assertBooksAgree($cid);
    }

    public function test_it_settles_bills_only_never_a_deposit_or_credit(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'units' => $units] = $this->open();
        $lease = $this->tenantIn($owner, $units[1], '2026-11-01', ['deposit_minor' => 500000]);
        $deposit = RentalCharge::where('lease_id', $lease->id)->where('kind', 'deposit')->firstOrFail();
        $rent = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->firstOrFail();

        $this->refused('direct_too_much', fn () => app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500001]));
        $this->refused('direct_wrong_bill', fn () => app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000, 'split' => [['charge_id' => (int) $deposit->id, 'amount_minor' => 500000]]]));
        $this->refused('split_total', fn () => app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 400000, 'split' => [['charge_id' => (int) $rent->id, 'amount_minor' => 300000]]]));

        $p = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000]);
        $this->assertSame('paid', $rent->fresh()->status);
        $this->assertSame('open', $deposit->fresh()->status);
        $this->assertSame(0, Payments::credit($lease));
        $this->assertSame(0, (int) $p->unallocated_minor);

        // Only the deposit is left: nothing this can settle.
        $this->refused('direct_nothing_open', fn () => app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 1000]));
        $this->assertBooksAgree($cid);
    }

    public function test_a_part_payment_takes_its_part_of_the_commission_and_the_rest_ends_exact(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord, 'bill' => $bill] = $this->open();
        app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 100000]);
        $this->assertSame(-10000, LandlordAccounts::balance($landlord));
        // The rest comes to the office as cash: the landlord's balance gets its share as usual.
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 400000, 'method' => 'cash']);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(360000 - 10000, LandlordAccounts::balance($landlord));
        $this->assertSame(50000, (int) RentalAllocation::where('charge_id', $bill->id)->sum('commission_minor'));
        $this->assertBooksAgree($cid);
    }

    public function test_a_bill_for_the_agency_comes_off_the_landlords_balance_whole(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->open();
        app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000]);
        $fee = app(Billing::class)->charge($owner, $lease, ['description' => 'Late fee', 'amount_minor' => 20000, 'goes_to' => 'agency', 'due_date' => '2026-11-05'], 'manual');
        app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 20000]);
        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertSame(-70000, LandlordAccounts::balance($landlord));
        $s = LandlordAccounts::statement($landlord, '2026-11-01', '2026-11-30');
        $this->assertSame(-70000, $s['closing_minor']);
        $this->assertSame(50000, $s['totals']['commission_minor']);
        $this->assertSame(-20000, $s['totals']['other_minor']);
        $this->assertBooksAgree($cid);
    }

    public function test_it_stays_out_of_the_cash_book_and_the_collected_totals_but_not_the_commission(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->open();
        app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000, 'paid_on' => '2026-11-05']);

        $book = CashBook::statement($cid, '2026-11-01', '2026-11-30');
        $this->assertSame([], array_values(array_filter((array) $book['rows'], fn ($r) => (string) ($r['type'] ?? '') === 'collected_direct')));
        $this->assertSame(0, Figures::month($cid, Carbon::parse('2026-11-05'), null)['collected_minor']);

        $params = ['from' => '2026-11-01', 'to' => '2026-11-30'];
        $this->assertSame(0, (int) ((new RentalReports)->run('collections', $cid, null, $params)['totals']['amount_minor'] ?? 0));
        $commission = (new RentalReports)->run('commission', $cid, null, $params);
        $this->assertSame(50000, (int) $commission['totals']['commission_minor']);
        $this->assertStringContainsString('500,000', json_encode($commission['sections']));

        $income = (new RentalReports)->run('income_statement', $cid, null, $params);
        $this->assertStringContainsString('50000', json_encode($income['rows']));

        $monthly = (new RentalReports)->run('monthly_building', $cid, null, ['month' => '2026-11']);
        $this->assertSame(0, (int) ($monthly['figures']['income_minor'] ?? 0), 'Money that never came in is not income on the building report');
        $this->assertSame(50000, (int) ($monthly['figures']['commission_minor'] ?? 0));

        $receipts = (new RentalReports)->run('receipts', $cid, null, $params + ['view' => 'money']);
        $this->assertCount(0, $receipts['rows']);
        $others = (new RentalReports)->run('receipts', $cid, null, $params + ['view' => 'other']);
        $this->assertCount(1, $others['rows']);
        $this->assertSame('Paid to the landlord', $others['rows'][0]['method']);

        $tenant = (new RentalReports)->run('tenant_statement', $cid, null, $params + ['lease_id' => (int) $lease->id]);
        $this->assertStringContainsString('Paid to the landlord directly', json_encode($tenant['rows']));

        // The accountant's trial balance sees the row, and balances.
        $trial = (new RentalReports)->run('trial_balance', $cid, null, $params);
        $this->assertSame((int) $trial['totals']['debit_minor'], (int) $trial['totals']['credit_minor']);
        $this->assertBooksAgree($cid);
    }

    public function test_the_year_summary_agrees_with_the_statement(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->open();
        $p = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000, 'paid_on' => '2026-11-05']);
        $year = (new RentalReports)->run('landlord_year', $cid, null, ['landlord_id' => (int) $landlord->id, 'year' => '2026']);
        $this->assertSame(50000, (int) $year['totals']['commission_minor']);
        $this->assertSame(0, (int) $year['totals']['collected_minor']);
        $this->assertSame(0, (int) $year['totals']['other_minor']);
        $this->assertSame(-50000, (int) $year['totals']['closing_minor']);
        $this->assertStringContainsString('500,000', json_encode($year['sections']));

        app(Payments::class)->reverse($owner, $p, 'Typed on the wrong lease');
        $year = (new RentalReports)->run('landlord_year', $cid, null, ['landlord_id' => (int) $landlord->id, 'year' => '2026']);
        $this->assertSame(0, (int) $year['totals']['commission_minor']);
        $this->assertSame(0, (int) $year['totals']['closing_minor']);
        $this->assertBooksAgree($cid);
    }

    public function test_a_landlord_can_go_below_zero_and_a_payout_later_says_so(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->open();
        app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000]);
        $this->assertSame(-50000, LandlordAccounts::balance($landlord));
        $this->assertSame(1, MonthEndFigures::lookAt($cid, '2026-11')['below_zero']);
        $this->assertBooksAgree($cid);
    }

    public function test_a_reversal_reopens_the_bill_and_gives_the_commission_back(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord, 'bill' => $bill] = $this->open();
        $p = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000]);
        $done = app(Payments::class)->reverse($owner, $p, 'The landlord says it never came');

        $this->assertNotNull($done->reversed_at);
        $this->assertSame('open', $bill->fresh()->status);
        $this->assertSame(0, (int) $bill->fresh()->paid_minor);
        $this->assertSame(0, LandlordAccounts::balance($landlord));
        $this->assertSame(0, (int) RentalEntry::where('payment_id', $p->id)->sum('income_minor'));

        $s = LandlordAccounts::statement($landlord, '2026-11-01', '2026-11-30');
        $this->assertSame(0, $s['closing_minor']);
        $this->assertSame(0, $s['totals']['commission_minor']);
        $this->assertSame(0, $s['totals']['direct_minor'] ?? 0);
        $this->assertCount(2, $s['lines']);
        $this->assertSame('reversal', $s['lines'][1]['type']);
        $this->assertStringContainsString('Correction: Tenant paid you directly', $s['lines'][1]['description']);
        $this->assertSame([0, -50000, 50000, -500000], [$s['lines'][1]['gross_minor'], $s['lines'][1]['commission_minor'], $s['lines'][1]['amount_minor'], $s['lines'][1]['direct_minor']]);
        $this->assertBooksAgree($cid);
    }

    public function test_a_closed_month_refuses_the_date_with_or_without_commission_and_the_issued_statement_holds_it(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord, 'units' => $units] = $this->open();
        $none = $this->tenantIn($owner, $units[1], '2026-11-01', ['deposit_minor' => 0, 'commission' => ['type' => 'none']]);
        $p = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000, 'paid_on' => '2026-11-05']);
        Carbon::setTestNow('2026-12-03 09:00:00');
        app(MonthClose::class)->close($owner, '2026-11');

        $copy = StatementBook::current($landlord, '2026-11');
        $this->assertNotNull($copy);
        $line = collect((array) $copy->body['lines'])->firstWhere('type', 'collected_direct');
        $this->assertStringContainsString('Tenant paid you directly: 500,000. Commission 50,000.', $line['description']);
        $this->assertSame(500000, (int) $copy->body['totals']['direct_minor']);
        $this->assertSame(50000, (int) $copy->commission_minor);
        $this->assertSame(-50000, (int) $copy->closing_minor);
        $this->assertBooksAgree($cid);

        $this->refused('period_closed', fn () => app(Payments::class)->receiveDirect($owner, $none, ['amount_minor' => 100000, 'paid_on' => '2026-11-20']));
        $bill = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->firstOrFail();

        // Its reversal posts today: December's statement, and November's stays as issued.
        app(Payments::class)->reverse($owner, $p, 'The landlord says it never came');
        $this->assertSame(-50000, (int) StatementBook::current($landlord, '2026-11')->closing_minor);
        $this->assertSame('open', $bill->fresh()->status);
        $dec = LandlordAccounts::statement($landlord, '2026-12-01', '2026-12-31');
        $this->assertSame(0, $dec['closing_minor']);
        $this->assertBooksAgree($cid);
    }

    public function test_the_same_id_twice_is_one_payment_and_a_different_payload_is_refused(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->open();
        $uuid = (string) Str::uuid();
        $one = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 100000, 'uuid' => $uuid]);
        $two = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 100000, 'uuid' => $uuid]);
        $this->assertSame((int) $one->id, (int) $two->id);
        $this->assertSame(-10000, LandlordAccounts::balance($landlord));
        $this->refused('idempotency_conflict', fn () => app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 200000, 'uuid' => $uuid]));
        $this->assertBooksAgree($cid);
    }

    public function test_an_owner_run_account_has_no_landlord_to_pay_and_the_receive_door_refuses_it(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner');
        [, $units] = $this->building($owner, 1, 500000);
        $lease = $this->tenantIn($owner, $units[0], '2026-11-01', ['deposit_minor' => 0]);
        $this->refused('owner_mode', fn () => app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000]));
        $this->refused('method_invalid', fn () => app(Payments::class)->receive($owner, $lease, ['amount_minor' => 500000, 'method' => RentalPayment::DIRECT]));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_phone_is_not_offered_it_and_cannot_post_it(): void
    {
        ['owner' => $owner, 'lease' => $lease, 'cid' => $cid] = $this->open();
        $this->assertArrayNotHasKey(RentalPayment::DIRECT, (array) config('rentals.methods'));
        $this->assertNotContains(RentalPayment::DIRECT, RentalPayment::CASH_METHODS);
        $this->refused('method_invalid', fn () => app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => RentalPayment::DIRECT], 'app'));
        $this->assertSame(0, RentalPayment::where('lease_id', $lease->id)->count());
        $this->assertBooksAgree($cid);
    }

    public function test_the_receipt_says_what_happened_and_is_written_down(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease] = $this->open();
        $p = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000]);
        $parts = Receipt::parts($p);
        $this->assertContains(['Paid by', 'Paid to the landlord directly, recorded by the agency.'], $parts['lines']);
        $this->assertSame('Recorded by', collect($parts['after'])->last()[0]);
        $this->assertStringContainsString('Paid to the landlord directly', str_replace("\n", ' ', Receipt::text($p, 200)));
        $this->assertNotSame('', Receipt::pdf($p));
        $this->assertBooksAgree($cid);
    }

    public function test_a_bill_it_settled_cannot_silently_become_credit_at_move_out(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->open();
        $p = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000, 'paid_on' => '2026-11-05']);

        // Moving out on the day rent began: the bill for the month no longer stands, and the money never came through the agency.
        $data = ['date' => '2026-11-01'];
        $will = app(Leases::class)->moveOutPreview($lease, $data);
        $this->assertNotNull($will['blocked']);
        $this->refused('direct_settled', fn () => app(Leases::class)->moveOut($owner, $lease, $data));
        $this->assertSame('active', RentalLease::withoutGlobalScopes()->find($lease->id)->status);
        $this->assertSame(-50000, LandlordAccounts::balance($landlord));

        // Reverse it first, as the words say, and the move-out goes through.
        app(Payments::class)->reverse($owner, $p, 'Moving out: the landlord gives it back');
        $this->assertNull(app(Leases::class)->moveOutPreview($lease->fresh(), $data)['blocked']);
        app(Leases::class)->moveOut($owner, $lease->fresh(), $data);
        $this->assertSame(0, LandlordAccounts::balance($landlord));
        $this->assertBooksAgree($cid);
    }

    public function test_after_a_handover_it_settles_only_the_new_landlords_bills(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $old, 'p' => $property, 'bill' => $bill] = $this->open();
        $new = app(\App\Services\Rentals\Directory\Landlords::class)->create($owner, ['name' => 'Second Landlord', 'phone' => '+256700000999']);
        app(\App\Services\Rentals\Landlords\Handover::class)->handOver($owner, $property, ['landlord_id' => (int) $new->id, 'reason' => 'Sold']);
        $this->assertSame((int) $old->id, (int) $bill->fresh()->landlord_id);

        // November's bill stayed with the old landlord: the new one's tenant-at-the-gate money cannot settle it.
        $this->refused('direct_nothing_open', fn () => app(Payments::class)->receiveDirect($owner, $lease->fresh(), ['amount_minor' => 500000]));
        $this->assertBooksAgree($cid);
    }

    public function test_the_check_notices_a_row_that_moved_more_than_the_commission(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease] = $this->open();
        $p = app(Payments::class)->receiveDirect($owner, $lease, ['amount_minor' => 500000]);
        $this->assertBooksAgree($cid);
        $row = RentalEntry::where('payment_id', $p->id)->firstOrFail();
        DB::table('rental_entries')->where('id', $row->id)->update(['landlord_minor' => -60000, 'income_minor' => 60000]);
        $found = array_column((new RentalCheck)->run($cid), 'check');
        $this->assertContains('direct_journal', $found);
        // Leave the books as they were: the same row put back passes again.
        DB::table('rental_entries')->where('id', $row->id)->update(['landlord_minor' => -50000, 'income_minor' => 50000]);
        $this->assertSame([], (new RentalCheck)->run($cid));
        $this->assertSame(1, RentalSetting::withoutGlobalScopes()->where('company_id', $cid)->count());
    }
}

<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\RentalTenant;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Collections\Arrears;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Jobs\RentalDaily;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Meters;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalsModule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Estate Ledger's money paths end to end (docs/estate-ledger/04-architecture.md §5, ADR 0010): move-in bills, a payment split
 * oldest first with commission, credit applying itself, idempotent billing, part payments and reversals, late fees,
 * payouts and expenses, the landlord statement, move-out with the deposit settled, owner mode, the caretaker's scope,
 * meters, the part-month anchor and rent changes. Every test ends with rentals:check finding nothing.
 */
class RentalsMoneyFlowTest extends ApiTestCase
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

    /** @return array{0: \App\Models\Company, 1: \App\Models\User, 2: RentalLease} */
    private function marchLease(): array
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 2, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 300000,
            'items' => [['name' => 'Garbage', 'amount_minor' => 10000, 'goes_to' => 'agency']]]);

        return [$company, $owner, $lease];
    }

    public function test_move_in_bills_and_a_payment_splits_oldest_first_with_commission_and_credit(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        $cid = (int) $company->id;
        $this->assertSame(['deposit' => 300000, 'item' => 10000, 'rent' => 300000],
            RentalCharge::where('lease_id', $lease->id)->orderBy('kind')->pluck('amount_minor', 'kind')->map(fn ($v) => (int) $v)->all());
        $pos = Leases::position($lease);
        $this->assertSame(610000, $pos['owed_minor']);
        $this->assertSame('due', $pos['state']);

        $p = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 650000, 'method' => 'cash', 'paid_on' => '2026-03-01']);
        $this->assertSame('R-000001', $p->number);
        $this->assertSame(40000, (int) $p->unallocated_minor);
        $splits = RentalAllocation::where('payment_id', $p->id)->orderBy('id')->get(['amount_minor', 'commission_minor', 'landlord_minor', 'agency_minor', 'deposit_minor'])
            ->map(fn ($a) => array_map('intval', $a->toArray()))->all();
        $this->assertSame([
            ['amount_minor' => 300000, 'commission_minor' => 0, 'landlord_minor' => 0, 'agency_minor' => 0, 'deposit_minor' => 300000],
            ['amount_minor' => 300000, 'commission_minor' => 30000, 'landlord_minor' => 270000, 'agency_minor' => 0, 'deposit_minor' => 0],
            ['amount_minor' => 10000, 'commission_minor' => 0, 'landlord_minor' => 0, 'agency_minor' => 10000, 'deposit_minor' => 0],
        ], $splits);
        $this->assertSame(['cash_minor' => 650000, 'landlord_minor' => 270000, 'deposit_minor' => 300000, 'credit_minor' => 40000, 'income_minor' => 40000,
            'expense_minor' => 0, 'equity_minor' => 0], RentalLedger::totals($cid));
        $pos = Leases::position($lease->fresh());
        $this->assertEquals(['owed_minor' => 0, 'credit_minor' => 40000, 'deposit_held_minor' => 300000, 'state' => 'credit', 'paid_up_to' => '2026-04-03'],
            array_intersect_key($pos, array_flip(['owed_minor', 'credit_minor', 'deposit_held_minor', 'state', 'paid_up_to'])));
        $this->assertBooksAgree($cid);
    }

    public function test_billing_is_idempotent_credit_applies_itself_and_part_payments_reverse_cleanly(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        $cid = (int) $company->id;
        $pay = app(Payments::class);
        $pay->receive($owner, $lease, ['amount_minor' => 650000, 'method' => 'cash']);

        Carbon::setTestNow('2026-03-27 09:00:00');
        $this->assertSame(['bills' => 2, 'late_fees' => 0], app(RentalDaily::class)->run($cid));
        $this->assertSame(['bills' => 0, 'late_fees' => 0], app(RentalDaily::class)->run($cid));
        $april = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->where('period_start', '2026-04-01')->firstOrFail();
        $this->assertSame(40000, (int) $april->paid_minor);
        $this->assertSame([4000, 36000], [(int) RentalAllocation::where('charge_id', $april->id)->value('commission_minor'), (int) RentalAllocation::where('charge_id', $april->id)->value('landlord_minor')]);
        $this->assertSame(270000, Leases::position($lease->fresh())['owed_minor']);

        $a = $pay->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'mobile_money', 'reference' => 'MP123']);
        $b = $pay->receive($owner, $lease, ['amount_minor' => 170000, 'method' => 'cash']);
        $this->assertSame(0, Leases::position($lease->fresh())['owed_minor']);
        $this->assertSame(30000, (int) RentalAllocation::where('charge_id', $april->id)->sum('commission_minor'));

        $pay->reverse($owner, $b, 'Counted twice');
        $this->assertSame(170000, Leases::position($lease->fresh())['owed_minor']);
        $this->assertSame(140000, (int) $april->fresh()->paid_minor);
        $this->assertSame(270000 + 36000 + 90000, LandlordAccounts::balance($lease->landlord()->first()));
        $this->refused('already_reversed', fn () => $pay->reverse($owner, $b->fresh(), 'again'));
        $this->assertSame(650000 + 170000 - 170000, RentalLedger::cash($cid)['cash']);
        $this->assertSame(100000, RentalLedger::cash($cid)['mobile_money']);
        $this->assertBooksAgree($cid);
    }

    public function test_late_fee_once_payout_expense_and_statement(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        $cid = (int) $company->id;
        app(RentalsModule::class)->saveSettings($company, $owner, ['late_fee_type' => 'flat', 'late_fee_minor' => 20000, 'late_fee_to' => 'agency']);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 610000, 'method' => 'cash']);
        Carbon::setTestNow('2026-04-12 09:00:00');
        $this->assertSame(['bills' => 2, 'late_fees' => 1], app(RentalDaily::class)->run($cid));
        $this->assertSame(['bills' => 0, 'late_fees' => 0], app(RentalDaily::class)->run($cid));
        $fee = RentalCharge::where('lease_id', $lease->id)->where('kind', 'late_fee')->firstOrFail();
        $this->assertSame(['agency', 20000], [$fee->goes_to, (int) $fee->amount_minor]);
        $aging = Arrears::aging($cid);
        $this->assertSame(310000, $aging['total_minor']);
        $this->assertSame(1, $aging['leases']);

        $landlord = $lease->landlord()->first();
        $this->assertSame(270000, LandlordAccounts::balance($landlord));
        $this->refused('payout_above_balance', fn () => app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 300000, 'method' => 'cash']));
        $this->refused('insufficient_cash', fn () => app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 100000, 'method' => 'bank']));
        app(CashBook::class)->transfer($owner, 'cash', 'bank', 200000, '2026-04-12', 'Banked');
        $po = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 200000, 'method' => 'bank', 'reference' => 'EFT-9']);
        $this->assertSame('PO-0001', $po->number);
        app(Expenses::class)->record($owner, ['property_id' => $lease->property_id, 'category' => 'Repairs', 'description' => 'Gate hinge', 'amount_minor' => 50000, 'method' => 'cash']);
        app(Expenses::class)->record($owner, ['category' => 'Agency office', 'amount_minor' => 15000, 'method' => 'cash', 'charged_to' => 'agency']);
        $this->assertSame(20000, LandlordAccounts::balance($landlord));

        $st = LandlordAccounts::statement($landlord, '2026-03-01', '2026-04-30');
        $this->assertSame(0, $st['opening_minor']);
        $this->assertSame(20000, $st['closing_minor']);
        $this->assertSame(['collected_minor' => 300000, 'commission_minor' => 30000, 'expenses_minor' => 50000, 'payouts_minor' => 200000, 'deposits_minor' => 0, 'other_minor' => 0], $st['totals']);
        $this->assertSame(1, $st['occupancy']['occupied']);
        $this->assertSame(['cash' => 610000 - 200000 - 50000 - 15000, 'bank' => 0, 'mobile_money' => 0, 'cheque' => 0, 'total' => 345000], CashBook::balances($cid));
        $this->assertBooksAgree($cid);
    }

    public function test_move_out_uses_the_deposit_refunds_the_rest_and_frees_the_unit(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        $cid = (int) $company->id;
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 610000, 'method' => 'cash']);
        Carbon::setTestNow('2026-04-20 09:00:00');
        app(RentalDaily::class)->run($cid);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 150000, 'method' => 'cash']);
        $this->assertSame(160000, Leases::position($lease->fresh())['owed_minor']);

        $out = app(Leases::class)->moveOut($owner, $lease, ['date' => '2026-04-20', 'refund_deposit' => 'cash',
            'damages' => [['description' => 'Broken window', 'amount_minor' => 40000]], 'reason' => 'Relocated']);
        $this->assertSame(['forgiven_minor' => 0, 'deposit_used_minor' => 200000, 'deposit_refunded_minor' => 100000, 'deposit_kept_minor' => 0,
            'credit_refunded_minor' => 0, 'credit_kept_minor' => 0, 'still_owed_minor' => 0], $out['settlement']);
        $this->assertSame('ended', $out['lease']->status);
        $this->assertSame('former', RentalTenant::find($lease->tenant_id)->status);
        $this->assertFalse(RentalLease::where('unit_id', $lease->unit_id)->whereIn('status', RentalLease::LIVE)->exists());
        $this->assertSame(0, RentalLedger::totals($cid)['deposit_minor']);
        $this->assertSame(610000 + 150000 - 100000, RentalLedger::cash($cid)['cash']);
        $this->assertBooksAgree($cid);
    }

    public function test_unused_days_of_a_paid_period_go_back_to_the_tenant(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        $cid = (int) $company->id;
        Carbon::setTestNow('2026-04-02 09:00:00');
        app(RentalDaily::class)->run($cid);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 920000, 'method' => 'cash']); // deposit + March + April, fees too
        Carbon::setTestNow('2026-04-20 09:00:00');
        $out = app(Leases::class)->moveOut($owner, $lease, ['date' => '2026-04-20', 'unused_days' => true, 'refund_deposit' => 'cash', 'refund_credit' => 'cash']);
        // April has 30 days and 10 are left after the 20th: a third of the rent (100,000) and of the garbage fee (3,333).
        $this->assertSame(103333, $out['settlement']['forgiven_minor']);
        $this->assertSame(300000, $out['settlement']['deposit_refunded_minor']);
        $this->assertSame(103333, $out['settlement']['credit_refunded_minor']);
        $this->assertSame(270000 + 270000 - 90000, LandlordAccounts::balance($lease->landlord()->first()));
        $this->assertSame(['credit' => 0, 'deposit' => 0], ['credit' => RentalLedger::totals($cid)['credit_minor'], 'deposit' => RentalLedger::totals($cid)['deposit_minor']]);
        $this->assertBooksAgree($cid);
    }

    public function test_owner_mode_has_no_commission_and_pays_the_owner(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner');
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 1, 250000);
        $this->assertTrue((bool) $property->landlord->is_self);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 250000, 'method' => 'cash']);
        $this->assertSame(['landlord_minor' => 250000, 'income_minor' => 0], array_intersect_key(RentalLedger::totals($cid), array_flip(['landlord_minor', 'income_minor'])));
        $po = app(Payouts::class)->record($owner, $property->landlord, ['amount_minor' => 100000, 'method' => 'cash']);
        $this->assertStringStartsWith('Owner drawing', (string) \App\Models\RentalEntry::where('payout_id', $po->id)->value('description'));
        $this->assertSame(150000, LandlordAccounts::balance($property->landlord));
        $this->refused('owner_mode', fn () => app(\App\Services\Rentals\Directory\Landlords::class)->create($owner, ['name' => 'Someone']));
        $this->assertBooksAgree($cid);
    }

    public function test_a_caretaker_sees_and_collects_only_on_their_property(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$a, $ua] = $this->building($owner, 1);
        [$b, $ub] = $this->building($owner, 1);
        $la = $this->tenantIn($owner, $ua[0], '2026-02-01', ['deposit_minor' => 0]);
        $lb = $this->tenantIn($owner, $ub[0], '2026-02-01', ['deposit_minor' => 0]);
        $care = $this->rentalsStaff($company, 'rentals_caretaker');
        app(Properties::class)->assignStaff($owner, $a, [$care->id]);

        $p = app(Payments::class)->receive($care, $la, ['amount_minor' => 50000, 'method' => 'cash']);
        $this->assertSame((int) $care->id, (int) $p->received_by_id);
        $this->refused('not_found', fn () => app(Payments::class)->receive($care, $lb, ['amount_minor' => 50000, 'method' => 'cash']));
        $this->refused('forbidden', fn () => app(Payments::class)->reverse($care, $p, 'no'));
        $this->assertSame([(int) $la->id], array_column(Arrears::list($cid, $care), 'lease_id'));
        $this->assertCount(2, Arrears::list($cid, $owner));
        $this->assertBooksAgree($cid);
    }

    public function test_the_same_payment_sent_twice_is_one_payment(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        $uuid = (string) Str::uuid();
        $one = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash', 'uuid' => $uuid], 'app');
        $two = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash', 'uuid' => $uuid], 'app');
        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, RentalPayment::where('uuid', $uuid)->count());
        $this->refused('idempotency_conflict', fn () => app(Payments::class)->receive($owner, $lease, ['amount_minor' => 90000, 'method' => 'cash', 'uuid' => $uuid]));
        $this->refused('date_in_future', fn () => app(Payments::class)->receive($owner, $lease, ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-03-02']));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_meter_readings_bill_the_units_used(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        app(Properties::class)->update($owner, $lease->property()->first(), ['water_rate_minor' => 5000]);
        $m = app(Meters::class);
        $first = $m->record($owner, ['unit_id' => $lease->unit_id, 'utility' => 'water', 'reading' => '100', 'read_on' => '2026-03-01']);
        $this->assertNull($first->charge_id);
        Carbon::setTestNow('2026-03-31 09:00:00');
        $second = $m->record($owner, ['unit_id' => $lease->unit_id, 'utility' => 'water', 'reading' => '112.5', 'read_on' => '2026-03-31']);
        $this->assertSame(62500, (int) $second->amount_minor);
        $this->assertSame('utility', RentalCharge::find($second->charge_id)->kind);
        $this->refused('reading_lower', fn () => $m->record($owner, ['unit_id' => $lease->unit_id, 'utility' => 'water', 'reading' => '110', 'read_on' => '2026-03-31']));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_part_month_anchor_rent_change_waiver_and_void(): void
    {
        Carbon::setTestNow('2026-03-20 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-20', ['anchor' => 'first', 'deposit_minor' => 0]);
        $this->assertSame(116129, (int) RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->value('amount_minor')); // 12 of 31 days

        Carbon::setTestNow('2026-03-28 09:00:00');
        app(Billing::class)->raise($lease);
        $this->refused('period_billed', fn () => app(Leases::class)->changeRent($owner, $lease->fresh(), 350000, '2026-04-01'));
        app(Leases::class)->changeRent($owner, $lease->fresh(), 350000, '2026-05-01', 'Yearly review');
        Carbon::setTestNow('2026-04-28 09:00:00');
        app(Billing::class)->raise($lease);
        $this->assertSame(350000, (int) RentalCharge::where('lease_id', $lease->id)->where('period_start', '2026-05-01')->value('amount_minor'));

        $oneOff = app(Billing::class)->charge($owner, $lease, ['description' => 'Spare key', 'amount_minor' => 15000, 'goes_to' => 'agency']);
        app(Billing::class)->void($owner, $oneOff, 'Key found');
        $this->assertSame('void', $oneOff->fresh()->status);
        $april = RentalCharge::where('lease_id', $lease->id)->where('period_start', '2026-04-01')->firstOrFail();
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 116129 + 100000, 'method' => 'cash']);
        $this->refused('charge_paid', fn () => app(Billing::class)->void($owner, $april->fresh(), 'no'));
        app(Payments::class)->waive($owner, $april->fresh(), 50000, 'Landlord agreed: water was off for a week');
        $this->assertSame(150000, $april->fresh()->left());
        $this->refused('nothing_to_waive', fn () => app(Payments::class)->waive($owner, $april->fresh(), 150001, 'too much'));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_tenancy_brought_in_from_another_system_starts_with_its_opening_figures(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2024-05-15', ['deposit_minor' => 300000,
            'import' => ['as_of' => '2026-03-01', 'owed_minor' => 450000, 'deposit_held_minor' => 300000]]);
        $this->assertSame('2026-03-14', $lease->billed_until->toDateString());
        $this->assertSame(0, RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->count()); // nothing from 2024 is billed again
        $pos = Leases::position($lease->fresh());
        $this->assertSame([450000, 300000], [$pos['owed_minor'], $pos['deposit_held_minor']]);
        $this->assertSame(['deposit_minor' => 300000, 'equity_minor' => -300000], array_intersect_key(RentalLedger::totals($cid), array_flip(['deposit_minor', 'equity_minor'])));

        Carbon::setTestNow('2026-03-12 09:00:00');
        app(RentalDaily::class)->run($cid);
        $this->assertSame(['2026-03-15'], RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->pluck('period_start')->map(fn ($d) => $d->toDateString())->all());
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 450000, 'method' => 'cash']);
        $this->assertSame(405000, LandlordAccounts::balance($lease->landlord()->first())); // the opening balance carries the lease's 10 %
        $this->assertBooksAgree($cid);
    }

    public function test_flat_commission_is_a_fee_per_month_of_rent(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$property, $units] = $this->building($owner, 2, 600000, ['commission_type' => 'flat', 'commission_minor' => 50000]);
        $quarterly = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0, 'cycle_months' => 3,
            'items' => [['name' => 'Security', 'amount_minor' => 20000, 'goes_to' => 'landlord', 'commissionable' => true]]]);
        $rent = RentalCharge::where('lease_id', $quarterly->id)->where('kind', 'rent')->firstOrFail();
        $this->assertSame([1800000, 'flat', 150000], [(int) $rent->amount_minor, $rent->commission_type, (int) $rent->commission_minor]);
        $this->assertSame('none', RentalCharge::where('lease_id', $quarterly->id)->where('kind', 'item')->value('commission_type'));
        app(Payments::class)->receive($owner, $quarterly, ['amount_minor' => 600000, 'method' => 'cash']);
        $this->assertSame(50000, (int) RentalAllocation::where('charge_id', $rent->id)->sum('commission_minor')); // a third paid, a third of the fee
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_leaving_on_the_first_day_of_a_period_is_not_billed_for_it(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        $cid = (int) $company->id;
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 610000, 'method' => 'cash']);
        Carbon::setTestNow('2026-03-20 09:00:00');
        app(Leases::class)->notice($owner, $lease, '2026-03-20', '2026-04-01');
        Carbon::setTestNow('2026-03-28 09:00:00');
        $this->assertSame(['bills' => 0, 'late_fees' => 0], app(RentalDaily::class)->run($cid), 'April starts on the move-out day');
        Carbon::setTestNow('2026-04-01 17:00:00');
        $out = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-04-01', 'refund_deposit' => 'cash']);
        $this->assertEquals(['still_owed_minor' => 0, 'deposit_refunded_minor' => 300000], array_intersect_key($out['settlement'], array_flip(['still_owed_minor', 'deposit_refunded_minor'])));
        $this->assertBooksAgree($cid);
    }

    public function test_three_hundred_random_operations_keep_the_books_true(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['late_fee_type' => 'percent', 'late_fee_bp' => 500]);
        $cid = (int) $company->id;
        [, $units] = $this->building($owner, 4, 0);
        $leases = [];
        foreach ($units as $i => $u) {
            $leases[] = $this->tenantIn($owner, $u, '2026-01-0'.($i + 1), ['rent_minor' => 200000 + 50000 * $i, 'cycle_months' => [1, 1, 3, 1][$i],
                'deposit_minor' => 100000 * $i, 'items' => $i % 2 ? [['name' => 'Security', 'amount_minor' => 15000, 'goes_to' => 'landlord', 'commissionable' => true]] : []]);
        }
        mt_srand(7);
        $pay = app(Payments::class);
        $day = Carbon::parse('2026-01-01');
        for ($k = 0; $k < 300; $k++) {
            $lease = $leases[mt_rand(0, 3)]->fresh();
            $op = mt_rand(0, 9);
            try {
                if ($op <= 4) {
                    $pay->receive($owner, $lease, ['amount_minor' => mt_rand(1, 40) * 10000, 'method' => ['cash', 'mobile_money', 'bank'][mt_rand(0, 2)]]);
                } elseif ($op === 5 && ($p = RentalPayment::where('lease_id', $lease->id)->whereNull('reversed_at')->whereIn('method', ['cash', 'mobile_money', 'bank'])->inRandomOrder()->first())) {
                    $pay->reverse($owner, $p, 'random');
                } elseif ($op === 6 && ($c = RentalCharge::where('lease_id', $lease->id)->where('status', 'open')->inRandomOrder()->first())) {
                    $pay->waive($owner, $c, max(1, intdiv($c->left(), 2)), 'random');
                } elseif ($op === 7 && ($credit = Payments::credit($lease)) > 0 && RentalLedger::cash($cid)['cash'] > $credit) {
                    $pay->refundCredit($owner, $lease, intdiv($credit, 2) ?: $credit, 'cash', $day);
                } else {
                    $day = $day->copy()->addDays(mt_rand(1, 9));
                    Carbon::setTestNow($day->format('Y-m-d').' 09:00:00');
                    app(RentalDaily::class)->run($cid, $day);
                }
            } catch (BusinessRuleException $e) {
                $this->assertContains($e->errorCode(), ['payment_refunded', 'insufficient_cash', 'credit_short', 'nothing_to_waive']);
            }
            if ($k % 50 === 49) {
                $this->assertBooksAgree($cid);
            }
        }
        $t = RentalLedger::totals($cid);
        $this->assertSame($t['cash_minor'], $t['landlord_minor'] + $t['deposit_minor'] + $t['credit_minor'] + $t['income_minor'] - $t['expense_minor'] + $t['equity_minor']);
        $this->assertBooksAgree($cid);
    }
}

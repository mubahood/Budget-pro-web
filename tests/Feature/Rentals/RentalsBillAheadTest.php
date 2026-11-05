<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalPayment;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Jobs\RentalDaily;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Payments\Receipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Rent paid ahead, billed now (LC-07): the second choice at the till, beside holding it as credit (KR-12, the default). Real bills with their commission, the
 * usual money rows, nothing billed twice, and an early move-out takes it back. Invented figures: rent 650,000 at 10%. Every test ends with rentals:check finding nothing.
 */
class RentalsBillAheadTest extends ApiTestCase
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

    /** A lease on rent 650,000 at 10%, paid up for November. @return array<string, mixed> */
    private function paidUp(array $lease = [], array $settings = []): array
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', $settings);
        [$p, $units] = $this->building($owner, 2, 650000);
        $l = $this->tenantIn($owner, $units[0], '2026-11-01', $lease + ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $l, ['amount_minor' => (int) RentalCharge::where('lease_id', $l->id)->where('status', 'open')->sum('amount_minor'), 'method' => 'cash']);

        return ['company' => $company, 'owner' => $owner, 'cid' => (int) $company->id, 'p' => $p, 'units' => $units, 'lease' => $l->fresh(), 'landlord' => RentalLandlord::withoutGlobalScopes()->findOrFail($p->landlord_id)];
    }

    private function totals(int $cid): array
    {
        return RentalLedger::totals($cid);
    }

    public function test_billing_two_months_ahead_pays_the_landlord_today_and_the_night_bills_nothing_more(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->paidUp();
        $before = $this->totals($cid);
        $share = LandlordAccounts::balance($landlord);
        $plan = app(Billing::class)->aheadPlan($lease, 1300000);
        $this->assertSame(2, $plan['max_periods']);
        $this->assertSame(1170000, array_sum(array_column($plan['periods'], 'landlord_minor')));
        $this->assertSame(130000, array_sum(array_column($plan['periods'], 'commission_minor')));
        $this->assertSame('2027-01-31', $plan['periods'][1]['end']);
        $this->assertSame(1300000, $plan['over_minor']);

        $p = app(Billing::class)->receiveAhead($owner, $lease, ['amount_minor' => 1300000, 'method' => 'cash'], 2);
        $after = $this->totals($cid);
        $this->assertSame(1300000, $after['cash_minor'] - $before['cash_minor']);
        $this->assertSame(0, $after['credit_minor'] - $before['credit_minor'], 'none of it stays as credit');
        $this->assertSame(1170000, LandlordAccounts::balance($landlord) - $share);
        $this->assertSame(130000, $after['income_minor'] - $before['income_minor']);
        $this->assertSame(0, (int) $p->unallocated_minor);
        $bills = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->orderBy('period_start')->get();
        $this->assertSame(['2026-11-01', '2026-12-01', '2027-01-01'], $bills->map(fn ($b) => $b->period_start->toDateString())->all());
        $this->assertTrue($bills->every(fn ($b) => $b->status === 'paid' && $b->commission_type === 'percent' && (int) $b->commission_bp === 1000));
        $lease = $lease->fresh();
        $this->assertSame('2027-01-31', $lease->billed_until->toDateString());
        $pos = Leases::position($lease);
        $this->assertSame('2027-01-31', $pos['paid_up_to']);
        $this->assertSame('2027-02-01', $pos['next_due']['date']);
        $this->assertSame(2, RentalAllocation::where('payment_id', $p->id)->count());
        $this->assertStringContainsString('Jan 2027', Receipt::text($p), 'the receipt names the period covered');

        foreach (['2026-11-29', '2026-12-28', '2027-01-20'] as $night) {
            Carbon::setTestNow($night.' 01:40:00');
            $this->assertSame(0, app(RentalDaily::class)->run($cid)['bills'], "{$night}: nothing more to bill");
        }
        Carbon::setTestNow('2027-01-28 01:40:00');
        $this->assertSame(1, app(RentalDaily::class)->run($cid)['bills'], 'February is billed as usual');
        $this->assertBooksAgree($cid);
    }

    public function test_fewer_periods_may_be_chosen_and_the_rest_stays_as_credit(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->paidUp();
        $share = LandlordAccounts::balance($landlord);
        $p = app(Billing::class)->receiveAhead($owner, $lease, ['amount_minor' => 1400000, 'method' => 'bank'], 1);
        $this->assertSame(750000, (int) $p->unallocated_minor);
        $this->assertSame(585000, LandlordAccounts::balance($landlord) - $share);
        $this->assertSame('2026-12-31', $lease->fresh()->billed_until->toDateString());
        $this->assertSame(750000, Payments::credit($lease->fresh()));
        $this->refused('ahead_not_covered', fn () => app(Billing::class)->receiveAhead($owner, $lease->fresh(), ['amount_minor' => 100000, 'method' => 'cash'], 3), 'credit plus money covers only one');
        $this->assertBooksAgree($cid);
    }

    public function test_it_counts_money_already_paid_ahead_and_what_is_owed_comes_first(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$p, $units] = $this->building($owner, 1, 650000);
        $lease = $this->tenantIn($owner, $units[0], '2026-11-01', ['deposit_minor' => 0]);
        $landlord = RentalLandlord::withoutGlobalScopes()->findOrFail($p->landlord_id);
        $plan = app(Billing::class)->aheadPlan($lease, 1300000);
        $this->assertSame(650000, $plan['open_minor']);
        $this->assertSame(1, $plan['max_periods'], 'November is owed first');
        app(Billing::class)->receiveAhead($owner, $lease, ['amount_minor' => 1300000, 'method' => 'cash'], 1);
        $this->assertSame(1170000, LandlordAccounts::balance($landlord));
        $this->assertSame(0, Payments::credit($lease->fresh()));
        app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 300000, 'method' => 'cash']);
        $this->assertSame(1, app(Billing::class)->aheadPlan($lease->fresh(), 350000)['max_periods'], 'with the credit, it covers January');
        app(Billing::class)->receiveAhead($owner, $lease->fresh(), ['amount_minor' => 350000, 'method' => 'cash'], 1);
        $this->assertSame(0, Payments::credit($lease->fresh()));
        $this->assertSame(1755000, LandlordAccounts::balance($landlord));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_fees_beside_the_rent_follow_the_same_period_and_their_own_rule(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->paidUp([
            'items' => [['name' => 'Garbage', 'amount_minor' => 10000, 'goes_to' => 'agency'], ['name' => 'Security', 'amount_minor' => 40000, 'goes_to' => 'landlord', 'commissionable' => true]]]);
        $share = LandlordAccounts::balance($landlord);
        $income = $this->totals($cid)['income_minor'];
        $plan = app(Billing::class)->aheadPlan($lease, 1400000);
        $this->assertSame(2, $plan['max_periods']);
        $this->assertSame(700000, $plan['periods'][0]['amount_minor']);
        $this->assertSame(10000, $plan['periods'][0]['agency_minor']);
        $this->assertSame(69000, $plan['periods'][0]['commission_minor']);
        $this->assertSame(621000, $plan['periods'][0]['landlord_minor']);
        app(Billing::class)->receiveAhead($owner, $lease, ['amount_minor' => 1400000, 'method' => 'cash'], 2);
        $this->assertSame(1242000, LandlordAccounts::balance($landlord) - $share, 'what the choice said');
        $this->assertSame(158000, $this->totals($cid)['income_minor'] - $income);
        $this->assertSame(2, RentalCharge::where('lease_id', $lease->id)->where('kind', 'item')->where('goes_to', 'agency')->where('period_start', '>=', '2026-12-01')->count());
        $this->assertBooksAgree($cid);
    }

    public function test_a_fixed_term_and_a_planned_move_out_stop_it(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease] = $this->paidUp(['end_date' => '2026-12-31']);
        $plan = app(Billing::class)->aheadPlan($lease, 1950000);
        $this->assertSame(1, $plan['max_periods']);
        $this->assertSame(1, $plan['can_bill']);
        $this->assertSame('2027-01-01', $plan['stop_on']);
        $bills = RentalCharge::where('lease_id', $lease->id)->count();
        $this->refused('ahead_not_covered', fn () => app(Billing::class)->receiveAhead($owner, $lease, ['amount_minor' => 1300000, 'method' => 'cash'], 2));
        $this->refused('ahead_past_end', fn () => app(Billing::class)->raiseAhead($owner, $lease, 2));
        $this->assertSame($bills, RentalCharge::where('lease_id', $lease->id)->count(), 'refused: no bill made');
        app(Billing::class)->receiveAhead($owner, $lease, ['amount_minor' => 1300000, 'method' => 'cash'], 1);
        $this->assertSame(650000, Payments::credit($lease->fresh()), 'the rest stays as credit');

        ['owner' => $owner2, 'cid' => $cid2, 'lease' => $moving] = $this->paidUp();
        app(Leases::class)->notice($owner2, $moving, '2026-11-05', '2026-12-15');
        $moving = $moving->fresh();
        $this->assertSame(1, app(Billing::class)->aheadPlan($moving, 1950000)['can_bill'], 'December starts before the day, January does not');
        $this->refused('ahead_past_end', fn () => app(Billing::class)->raiseAhead($owner2, $moving, 2));
        $this->assertBooksAgree($cid);
        $this->assertBooksAgree($cid2);
    }

    public function test_reversing_the_payment_leaves_the_bills_open_and_a_later_payment_settles_them(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->paidUp();
        $share = LandlordAccounts::balance($landlord);
        $p = app(Billing::class)->receiveAhead($owner, $lease, ['amount_minor' => 1300000, 'method' => 'cash'], 2);
        app(Payments::class)->reverse($owner, $p, 'The cheque bounced');
        $this->assertSame($share, LandlordAccounts::balance($landlord));
        $open = RentalCharge::where('lease_id', $lease->id)->where('status', 'open')->orderBy('period_start')->get();
        $this->assertCount(2, $open);
        $pos = Leases::position($lease->fresh());
        $this->assertSame(1300000, $pos['owed_minor']);
        $this->assertSame(0, $pos['overdue_minor'], 'due in the future, so not overdue');
        $this->assertSame('due', $pos['state']);
        app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 1300000, 'method' => 'cash']);
        $this->assertSame($share + 1170000, LandlordAccounts::balance($landlord));
        $this->assertSame(0, RentalCharge::where('lease_id', $lease->id)->where('status', 'open')->count());
        $this->assertBooksAgree($cid);
    }

    public function test_an_early_move_out_takes_it_back_and_says_so(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->paidUp();
        $start = LandlordAccounts::balance($landlord);
        app(Billing::class)->receiveAhead($owner, $lease, ['amount_minor' => 1300000, 'method' => 'cash'], 2);
        app(Payouts::class)->record($owner, $landlord->fresh(), ['amount_minor' => $start + 1170000, 'method' => 'cash']);
        $this->assertSame(0, LandlordAccounts::balance($landlord));
        $income = $this->totals($cid)['income_minor'];

        Carbon::setTestNow('2027-01-01 09:00:00');
        $data = ['date' => '2027-01-01'];
        $preview = app(Leases::class)->moveOutPreview($lease->fresh(), $data);
        $this->assertSame(650000, $preview['taken_back_minor']);
        $this->assertSame(65000, $preview['commission_back_minor']);
        $this->assertSame([['landlord_id' => (int) $landlord->id, 'name' => $landlord->name, 'is_self' => false, 'taken_back_minor' => 585000, 'balance_after_minor' => -585000]], $preview['landlords_back']);

        $this->refused('insufficient_cash', function () use ($owner, $lease, $data) {
            app(Leases::class)->moveOut($owner, $lease->fresh(), $data + ['refund_credit' => 'bank']);
        });
        $this->assertTrue($lease->fresh()->isLive(), 'refused whole, as with any refund the account cannot cover');
        app(CashBook::class)->opening($owner, 'bank', 650000, Carbon::parse('2027-01-01'));
        $out = app(Leases::class)->moveOut($owner, $lease->fresh(), $data + ['refund_credit' => 'bank']);
        $this->assertSame(650000, $out['settlement']['credit_refunded_minor']);
        $this->assertSame(-585000, LandlordAccounts::balance($landlord), 'the landlord owes you, as the preview said');
        $this->assertSame(65000, $income - $this->totals($cid)['income_minor']);
        $this->assertSame('void', RentalCharge::where('lease_id', $lease->id)->where('period_start', '2027-01-01')->value('status'));
        $this->assertSame(1, RentalEntry::where('lease_id', $lease->id)->where('type', 'unapplied')->count());
        $this->assertSame(0, Payments::credit($lease->fresh()));
        $this->assertBooksAgree($cid);
    }

    public function test_keeping_the_credit_at_move_out_and_flat_commission_are_exact(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->paidUp(['commission' => ['type' => 'flat', 'minor' => 65000, 'bp' => 0], 'cycle_months' => 3, 'start_date' => '2026-11-01']);
        $share = LandlordAccounts::balance($landlord);
        $plan = app(Billing::class)->aheadPlan($lease, 1950000);
        $this->assertSame(1, $plan['max_periods']);
        $this->assertSame(195000, $plan['periods'][0]['commission_minor'], 'three months of the flat fee');
        app(Billing::class)->receiveAhead($owner, $lease, ['amount_minor' => 1950000, 'method' => 'cash'], 1);
        $this->assertSame(1755000, LandlordAccounts::balance($landlord) - $share);
        $bill = RentalCharge::where('lease_id', $lease->id)->where('period_start', '2027-02-01')->firstOrFail();
        $this->assertSame(['flat', 195000], [$bill->commission_type, (int) $bill->commission_minor]);
        Carbon::setTestNow('2027-02-01 09:00:00');
        $out = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2027-02-01']);
        $this->assertSame(1950000, $out['settlement']['credit_kept_minor']);
        $this->assertSame(0, LandlordAccounts::balance($landlord) - $share);
        $this->assertBooksAgree($cid);
    }

    public function test_the_same_uuid_twice_is_one_payment_and_bills_nothing_more(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease] = $this->paidUp();
        $uuid = (string) Str::uuid();
        $data = ['amount_minor' => 1300000, 'method' => 'cash', 'uuid' => $uuid];
        $first = app(Billing::class)->receiveAhead($owner, $lease, $data, 2);
        $bills = RentalCharge::where('lease_id', $lease->id)->count();
        $until = $lease->fresh()->billed_until->toDateString();
        $again = app(Billing::class)->receiveAhead($owner, $lease->fresh(), $data, 2);
        $this->assertSame((int) $first->id, (int) $again->id);
        $this->assertSame($bills, RentalCharge::where('lease_id', $lease->id)->count());
        $this->assertSame($until, $lease->fresh()->billed_until->toDateString());
        $this->assertSame(1, RentalPayment::where('uuid', $uuid)->count());
        $this->refused('idempotency_conflict', fn () => app(Billing::class)->receiveAhead($owner, $lease->fresh(), ['amount_minor' => 999, 'method' => 'cash', 'uuid' => $uuid], 1));
        $this->assertBooksAgree($cid);
    }

    public function test_a_closed_month_refuses_the_whole_of_it_and_a_refusal_leaves_no_bill(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease] = $this->paidUp();
        Carbon::setTestNow('2026-12-10 09:00:00');
        app(RentalDaily::class)->run($cid);
        app(MonthClose::class)->close($owner, '2026-11');
        $lease = $lease->fresh();
        $bills = RentalCharge::where('lease_id', $lease->id)->count();
        $until = $lease->billed_until->toDateString();
        $this->refused('period_closed', fn () => app(Billing::class)->receiveAhead($owner, $lease, ['amount_minor' => 1950000, 'method' => 'cash', 'paid_on' => '2026-11-20'], 2));
        $this->assertSame($bills, RentalCharge::where('lease_id', $lease->id)->count(), 'the bills went with the refusal');
        $this->assertSame($until, $lease->fresh()->billed_until->toDateString());
        $this->refused('date_in_future', fn () => app(Billing::class)->receiveAhead($owner, $lease->fresh(), ['amount_minor' => 1950000, 'method' => 'cash', 'paid_on' => '2026-12-20'], 2));
        $this->assertSame($bills, RentalCharge::where('lease_id', $lease->id)->count());
        app(Billing::class)->receiveAhead($owner, $lease->fresh(), ['amount_minor' => 1950000, 'method' => 'cash'], 2);
        $this->assertSame('2027-02-28', $lease->fresh()->billed_until->toDateString(), 'dated today it goes through');
        $this->assertBooksAgree($cid);
    }

    public function test_who_may_and_owner_mode_and_other_refusals(): void
    {
        ['company' => $company, 'owner' => $owner, 'cid' => $cid, 'lease' => $lease] = $this->paidUp();
        $care = $this->rentalsStaff($company, 'rentals_caretaker');
        $this->refused('forbidden', fn () => app(Billing::class)->receiveAhead($care, $lease, ['amount_minor' => 650000, 'method' => 'cash'], 1));
        $this->refused('forbidden', fn () => app(Billing::class)->raiseAhead($care, $lease, 1));
        $accountant = $this->rentalsStaff($company, 'rentals_accountant');
        $this->assertSame(1, (int) app(Billing::class)->aheadPlan($lease, 650000)['max_periods']);
        app(Billing::class)->receiveAhead($accountant, $lease, ['amount_minor' => 650000, 'method' => 'cash'], 1);
        $this->refused('ahead_with_split', fn () => app(Billing::class)->receiveAhead($owner, $lease->fresh(), ['amount_minor' => 650000, 'method' => 'cash', 'split' => []], 1));
        $this->refused('ahead_invalid', fn () => app(Billing::class)->raiseAhead($owner, $lease->fresh(), 13));
        $this->refused('ahead_invalid', fn () => app(Billing::class)->raiseAhead($owner, $lease->fresh(), 0));
        $this->assertBooksAgree($cid);

        [, $other] = $this->rentalsAccount();
        $this->refused('not_found', fn () => app(Billing::class)->raiseAhead($other, $lease->fresh(), 1));
        $this->refused('not_found', fn () => app(Billing::class)->receiveAhead($other, $lease->fresh(), ['amount_minor' => 650000, 'method' => 'cash'], 1));
    }

    public function test_owner_mode_has_no_bill_ahead(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner');
        [, $units] = $this->building($owner, 1, 650000);
        $lease = $this->tenantIn($owner, $units[0], '2026-11-01', ['deposit_minor' => 0]);
        $this->assertSame(0, app(Billing::class)->aheadPlan($lease, 1950000)['max_periods']);
        $this->refused('owner_mode', fn () => app(Billing::class)->raiseAhead($owner, $lease, 1));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_through_the_api_a_resend_is_one_payment(): void
    {
        ['company' => $company, 'owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord, 'p' => $p] = $this->paidUp();
        $share = LandlordAccounts::balance($landlord);
        $h = $this->auth($owner->createToken('t')->plainTextToken);
        $body = ['uuid' => (string) Str::uuid(), 'lease_id' => $lease->id, 'amount_minor' => 1300000, 'method' => 'cash', 'bill_ahead_periods' => 2];
        $this->postJson('/api/v1/rentals/payments', $body, $h)->assertStatus(201);
        $this->assertSame($share + 1170000, LandlordAccounts::balance($landlord));
        $bills = RentalCharge::where('lease_id', $lease->id)->count();
        $this->postJson('/api/v1/rentals/payments', $body, $h)->assertOk()->assertJsonPath('message', 'Already received.');
        $this->assertSame($bills, RentalCharge::where('lease_id', $lease->id)->count());
        $this->postJson('/api/v1/rentals/payments', ['uuid' => (string) Str::uuid(), 'bill_ahead_periods' => 'two'] + $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'ahead_invalid');
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        $care = $this->auth($caretaker->createToken('t')->plainTextToken);
        $this->postJson('/api/v1/rentals/payments', ['uuid' => (string) Str::uuid()] + $body, $care)->assertStatus(404);
        app(Properties::class)->assignStaff($owner, $p, [(int) $caretaker->id]);
        $this->postJson('/api/v1/rentals/payments', ['uuid' => (string) Str::uuid()] + $body, $care)->assertStatus(403);
        [, $other] = $this->rentalsAccount();
        $this->postJson('/api/v1/rentals/payments', ['uuid' => (string) Str::uuid()] + $body, $this->auth($other->createToken('t')->plainTextToken))->assertStatus(404);
        $this->assertBooksAgree($cid);
    }

    public function test_three_hundred_random_operations_with_bill_ahead_and_handovers_keep_the_books_true(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['late_fee_type' => 'percent', 'late_fee_bp' => 500]);
        $cid = (int) $company->id;
        [$p, $units] = $this->building($owner, 4, 0);
        $other = app(\App\Services\Rentals\Directory\Landlords::class)->create($owner, ['name' => 'Second landlord']);
        $first = (int) $p->landlord_id;
        $leases = [];
        foreach ($units as $i => $u) {
            $leases[] = $this->tenantIn($owner, $u, '2026-01-0'.($i + 1), ['rent_minor' => 200000 + 50000 * $i, 'cycle_months' => [1, 1, 3, 1][$i], 'deposit_minor' => 100000 * $i,
                'items' => $i % 2 ? [['name' => 'Security', 'amount_minor' => 15000, 'goes_to' => 'landlord', 'commissionable' => true]] : []]);
        }
        mt_srand(11);
        $pay = app(Payments::class);
        $billing = app(Billing::class);
        $day = Carbon::parse('2026-01-01');
        $allowed = ['payment_refunded', 'insufficient_cash', 'credit_short', 'nothing_to_waive', 'ahead_not_covered', 'ahead_past_end', 'same_landlord', 'from_too_early'];
        for ($k = 0; $k < 300; $k++) {
            $lease = $leases[mt_rand(0, 3)]->fresh();
            $op = mt_rand(0, 11);
            try {
                if ($op <= 3) {
                    $pay->receive($owner, $lease, ['amount_minor' => mt_rand(1, 40) * 10000, 'method' => ['cash', 'mobile_money', 'bank'][mt_rand(0, 2)]]);
                } elseif ($op <= 5) {
                    $amount = mt_rand(1, 120) * 10000;
                    $plan = $billing->aheadPlan($lease, $amount);
                    if ($plan['max_periods'] > 0) {
                        $before = LandlordAccounts::balance(RentalLandlord::findOrFail($lease->landlord_id));
                        $billing->receiveAhead($owner, $lease, ['amount_minor' => $amount, 'method' => 'cash'], mt_rand(1, $plan['max_periods']));
                        $this->assertGreaterThanOrEqual($before, LandlordAccounts::balance(RentalLandlord::findOrFail($lease->landlord_id)));
                    }
                } elseif ($op === 6 && ($q = RentalPayment::where('lease_id', $lease->id)->whereNull('reversed_at')->whereIn('method', ['cash', 'mobile_money', 'bank'])->inRandomOrder()->first())) {
                    $pay->reverse($owner, $q, 'random');
                } elseif ($op === 7 && ($c = RentalCharge::where('lease_id', $lease->id)->where('status', 'open')->inRandomOrder()->first())) {
                    $pay->waive($owner, $c, max(1, intdiv($c->left(), 2)), 'random');
                } elseif ($op === 8 && ($credit = Payments::credit($lease)) > 0 && RentalLedger::cash($cid)['cash'] > $credit) {
                    $pay->refundCredit($owner, $lease, intdiv($credit, 2) ?: $credit, 'cash', $day);
                } elseif ($op === 9 && mt_rand(0, 9) === 0) {
                    app(\App\Services\Rentals\Landlords\Handover::class)->handOver($owner, $p->fresh(), ['landlord_id' => (int) $p->fresh()->landlord_id === $first ? $other->id : $first, 'reason' => 'random', 'from' => $day->toDateString()]);
                } else {
                    $day = $day->copy()->addDays(mt_rand(1, 9));
                    Carbon::setTestNow($day->format('Y-m-d').' 09:00:00');
                    app(RentalDaily::class)->run($cid, $day);
                }
            } catch (BusinessRuleException $e) {
                $this->assertContains($e->errorCode(), $allowed);
            }
            if ($k % 50 === 49) {
                $this->assertBooksAgree($cid);
            }
        }
        $t = RentalLedger::totals($cid);
        $this->assertSame($t['cash_minor'], $t['landlord_minor'] + $t['deposit_minor'] + $t['credit_minor'] + $t['income_minor'] - $t['expense_minor'] + $t['equity_minor']);
        $this->assertSame(CashBook::balances($cid)['total'], $t['cash_minor']);
        $this->assertBooksAgree($cid);
    }
}

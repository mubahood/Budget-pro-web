<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Meters;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Payments\Receipt;
use App\Support\Rentals\AmountWords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * The lease desk's kernel (docs/estate-ledger EL-04, EL-05): the cashier's split, part-month amounts typed by hand, and
 * the read-only previews of a move-in and a move-out, each proved equal to what the real write then does. Plus the
 * faults found while building the screens: a forgiven deposit counted as held, a refund outside a caretaker's
 * properties, and money taken on a closed lease. Every test ends with rentals:check finding nothing.
 */
class RentalsCashierAndMoveOutTest extends ApiTestCase
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

    /** @return array{0: \App\Models\Company, 1: \App\Models\User, 2: RentalLease, 3: array<string, RentalCharge>} */
    private function marchLease(array $over = []): array
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 2, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', $over + ['deposit_minor' => 300000,
            'items' => [['name' => 'Garbage', 'amount_minor' => 10000, 'goes_to' => 'agency']]]);

        return [$company, $owner, $lease, RentalCharge::where('lease_id', $lease->id)->get()->keyBy('kind')->all()];
    }

    public function test_the_cashier_splits_a_payment_by_hand_and_the_books_agree(): void
    {
        [$company, $owner, $lease, $bills] = $this->marchLease();
        $p = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 350000, 'method' => 'cash', 'split' => [
            ['charge_id' => $bills['rent']->id, 'amount_minor' => 300000],
            ['charge_id' => $bills['item']->id, 'amount_minor' => 10000],
            ['charge_id' => $bills['deposit']->id, 'amount_minor' => 40000],
        ]]);
        $this->assertSame(0, (int) $p->unallocated_minor);
        $this->assertSame('paid', $bills['rent']->fresh()->status);
        $this->assertSame('paid', $bills['item']->fresh()->status);
        $this->assertSame(40000, (int) $bills['deposit']->fresh()->paid_minor);
        // Commission follows the bill, not the order: 10 % of the rent, nothing on the rest.
        $this->assertSame(30000, (int) RentalAllocation::where('payment_id', $p->id)->sum('commission_minor'));
        $this->assertSame(40000, Deposits::held($lease));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_money_may_stay_as_credit_only_once_every_bill_is_covered(): void
    {
        [$company, $owner, $lease, $bills] = $this->marchLease();
        $pay = app(Payments::class);
        $all = [['charge_id' => $bills['deposit']->id, 'amount_minor' => 300000], ['charge_id' => $bills['rent']->id, 'amount_minor' => 300000], ['charge_id' => $bills['item']->id, 'amount_minor' => 10000]];
        $this->refused('split_total', fn () => $pay->receive($owner, $lease, ['amount_minor' => 400000, 'method' => 'cash', 'split' => [$all[1]]]));
        $p = $pay->receive($owner, $lease, ['amount_minor' => 700000, 'method' => 'cash', 'split' => $all]);
        $this->assertSame(90000, (int) $p->unallocated_minor);
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_split_is_refused_for_another_leases_bill_too_much_or_the_wrong_total(): void
    {
        [$company, $owner, $lease, $bills] = $this->marchLease();
        [, $units] = $this->building($owner, 1, 200000);
        $other = $this->tenantIn($owner, $units[0], '2026-03-01');
        $foreign = RentalCharge::where('lease_id', $other->id)->where('kind', 'rent')->firstOrFail();
        $pay = app(Payments::class);
        $this->refused('split_wrong_bill', fn () => $pay->receive($owner, $lease, ['amount_minor' => 200000, 'method' => 'cash', 'split' => [['charge_id' => $foreign->id, 'amount_minor' => 200000]]]));
        $this->refused('split_too_much', fn () => $pay->receive($owner, $lease, ['amount_minor' => 300001, 'method' => 'cash', 'split' => [['charge_id' => $bills['rent']->id, 'amount_minor' => 300001]]]));
        $this->refused('split_total', fn () => $pay->receive($owner, $lease, ['amount_minor' => 100, 'method' => 'cash', 'split' => [['charge_id' => $bills['rent']->id, 'amount_minor' => 200]]]));
        $this->refused('split_invalid', fn () => $pay->receive($owner, $lease, ['amount_minor' => 100, 'method' => 'cash', 'split' => [['charge_id' => $bills['rent']->id, 'amount_minor' => 100.5]]]));
        $pay->receive($owner, $lease, ['amount_minor' => 10000, 'method' => 'cash', 'split' => [['charge_id' => $bills['item']->id, 'amount_minor' => 10000]]]);
        $this->refused('split_closed', fn () => $pay->receive($owner, $lease, ['amount_minor' => 10000, 'method' => 'cash', 'split' => [['charge_id' => $bills['item']->id, 'amount_minor' => 10000]]]));
        $this->assertSame(1, RentalPayment::where('lease_id', $lease->id)->count());
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_the_same_uuid_with_another_split_is_a_conflict_and_the_same_split_a_replay(): void
    {
        [$company, $owner, $lease, $bills] = $this->marchLease();
        $pay = app(Payments::class);
        $uuid = (string) Str::uuid();
        $first = $pay->receive($owner, $lease, ['amount_minor' => 10000, 'method' => 'cash', 'uuid' => $uuid, 'split' => [['charge_id' => $bills['item']->id, 'amount_minor' => 10000]]]);
        $again = $pay->receive($owner, $lease, ['amount_minor' => 10000, 'method' => 'cash', 'uuid' => $uuid, 'split' => [['charge_id' => $bills['item']->id, 'amount_minor' => 10000]]]);
        $this->assertSame($first->id, $again->id);
        $this->refused('idempotency_conflict', fn () => $pay->receive($owner, $lease, ['amount_minor' => 10000, 'method' => 'cash', 'uuid' => $uuid, 'split' => [['charge_id' => $bills['rent']->id, 'amount_minor' => 10000]]]));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_split_payment_reverses_cleanly(): void
    {
        [$company, $owner, $lease, $bills] = $this->marchLease();
        $pay = app(Payments::class);
        $p = $pay->receive($owner, $lease, ['amount_minor' => 310000, 'method' => 'cash', 'split' => [['charge_id' => $bills['rent']->id, 'amount_minor' => 300000], ['charge_id' => $bills['item']->id, 'amount_minor' => 10000]]]);
        $pay->reverse($owner, $p, 'Wrong tenant');
        $this->assertSame(610000, Leases::position($lease->fresh())['owed_minor']);
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_the_suggested_split_flows_around_what_the_cashier_typed(): void
    {
        [, , $lease, $bills] = $this->marchLease();
        $pay = app(Payments::class);
        $auto = $pay->suggestSplit($lease, 350000);
        $this->assertSame([300000, 50000, 0], array_column($auto['lines'], 'amount_minor')); // deposit, rent, garbage: oldest first by KR-10
        $this->assertTrue($auto['ok']);
        $typed = $pay->suggestSplit($lease, 350000, [(int) $bills['rent']->id => 300000]);
        $this->assertSame([50000, 300000, 0], array_column($typed['lines'], 'amount_minor'));
        $this->assertTrue($typed['ok']);
        // Over the payment, more than a bill owes, and money stranded beside a bill cut short are all not ok.
        $this->assertSame(1, $pay->suggestSplit($lease, 299999, [(int) $bills['rent']->id => 300000])['over_minor']);
        $this->assertFalse($pay->suggestSplit($lease, 400000, [(int) $bills['item']->id => 20000])['ok']);
        $stranded = $pay->suggestSplit($lease, 700000, [(int) $bills['item']->id => 5000]);
        $this->assertSame(95000, $stranded['unplaced_minor']);
        $this->assertFalse($stranded['ok']);
        $this->assertSame(90000, $pay->suggestSplit($lease, 700000)['credit_minor']);
    }

    public function test_a_part_month_typed_at_move_in_replaces_the_suggestion_and_billing_never_makes_it_again(): void
    {
        Carbon::setTestNow('2026-03-17 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 3, 310000);
        $terms = ['start_date' => '2026-03-17', 'rent_minor' => 310000, 'anchor' => 'first', 'deposit_minor' => 0, 'cycle_months' => 1];

        // The suggestion is the calculation; moving in without typing bills exactly that.
        $preview = Leases::moveInPreview((int) $company->id, $terms);
        $this->assertSame(['start' => '2026-03-17', 'end' => '2026-03-31', 'days' => 15, 'month_days' => 31, 'suggested_minor' => 150000], $preview['part']);
        $plain = $this->tenantIn($owner, $units[0], '2026-03-17', $terms);
        $this->assertSame(150000, (int) RentalCharge::where('lease_id', $plain->id)->where('kind', 'rent')->value('amount_minor'));

        $typed = $this->tenantIn($owner, $units[1], '2026-03-17', $terms + ['part_rent_minor' => 120000]);
        $rents = fn (RentalLease $l) => RentalCharge::where('lease_id', $l->id)->where('kind', 'rent')->where('status', '!=', 'void')->orderBy('period_start')->pluck('amount_minor')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([120000], $rents($typed));

        $none = $this->tenantIn($owner, $units[2], '2026-03-17', $terms + ['part_rent_minor' => 0]);
        $this->assertSame([], $rents($none));
        $this->assertSame(0, Leases::position($none)['owed_minor']);

        // April's billing runs: the typed bills stand, the next periods are whole.
        Carbon::setTestNow('2026-03-30 09:00:00');
        app(Billing::class)->raise($typed);
        app(Billing::class)->raise($none);
        $this->assertSame([120000, 310000], $rents($typed));
        $this->assertSame([310000], $rents($none));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_the_move_in_preview_lists_the_bills_the_move_in_makes(): void
    {
        Carbon::setTestNow('2026-03-17 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 2, 310000);
        foreach ([['anchor' => 'first', 'cycle_months' => 1, 'part_rent_minor' => 99000], ['anchor' => 'start', 'cycle_months' => 3]] as $i => $over) {
            $terms = $over + ['start_date' => '2026-03-17', 'rent_minor' => 310000, 'deposit_minor' => 310000, 'items' => [['name' => 'Garbage', 'amount_minor' => 10000]]];
            $lease = $this->tenantIn($owner, $units[$i], '2026-03-17', $terms);
            $made = RentalCharge::where('lease_id', $lease->id)->where('status', '!=', 'void')->pluck('amount_minor')->map(fn ($v) => (int) $v)->sort()->values()->all();
            $said = collect(Leases::moveInPreview((int) $company->id, $terms)['lines'])->pluck('amount_minor')->sort()->values()->all();
            $this->assertSame($made, $said, json_encode($over));
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: int}> a scenario's choices and what the tenant pays first */
    public static function moveOuts(): iterable
    {
        yield 'unused days, refund both' => [['unused_days' => true, 'refund_deposit' => 'cash', 'refund_credit' => 'cash'], 700000];
        yield 'unused days, keep both' => [['unused_days' => true], 700000];
        yield 'a typed amount forgiven' => [['unused_minor' => 77000, 'refund_deposit' => 'cash'], 610000];
        yield 'nothing paid: no deposit to use, the bill dropped' => [['unused_days' => true], 0];
        yield 'part of the deposit paid' => [['refund_deposit' => 'cash'], 100000];
        yield 'damages take the deposit' => [['damages' => [['description' => 'Door', 'amount_minor' => 450000]], 'refund_deposit' => 'cash'], 610000];
        yield 'nothing forgiven, credit kept' => [[], 800000];
    }

    /** @dataProvider moveOuts */
    public function test_the_move_out_preview_says_what_the_move_out_then_does(array $choices, int $paid): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        if ($paid > 0) {
            app(Payments::class)->receive($owner, $lease, ['amount_minor' => $paid, 'method' => 'cash']);
        }
        Carbon::setTestNow('2026-03-20 09:00:00');
        $data = $choices + ['date' => '2026-03-20'];
        $preview = app(Leases::class)->moveOutPreview($lease->fresh(), $data);
        $done = app(Leases::class)->moveOut($owner, $lease->fresh(), $data)['settlement'];
        $this->assertSame($done, array_intersect_key($preview, $done));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_final_reading_in_the_preview_is_the_bill_the_reading_makes(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        Carbon::setTestNow('2026-03-01 09:00:00');
        [, $units] = $this->building($owner, 1, 300000, ['water_rate_minor' => 5000]);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Meters::class)->record($owner, ['unit_id' => $units[0]->id, 'utility' => 'water', 'reading' => '100', 'read_on' => '2026-03-01']);
        Carbon::setTestNow('2026-03-20 09:00:00');
        $data = ['date' => '2026-03-20', 'readings' => [['utility' => 'water', 'reading' => '112.5']]];
        $preview = app(Leases::class)->moveOutPreview($lease->fresh(), $data);
        $this->assertSame(62500, $preview['readings_minor']);
        $done = app(Leases::class)->moveOut($owner, $lease->fresh(), $data)['settlement'];
        $this->assertSame($done['still_owed_minor'], $preview['still_owed_minor']);
        $this->assertSame(62500, (int) RentalCharge::where('lease_id', $lease->id)->where('kind', 'utility')->value('amount_minor'));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_typed_amount_forgiven_spreads_rent_first_and_cannot_pass_the_periods_bills(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        Carbon::setTestNow('2026-03-20 09:00:00');
        $this->refused('unused_too_much', fn () => app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-20', 'unused_minor' => 310001]));
        $done = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-20', 'unused_minor' => 305000])['settlement'];
        $this->assertSame(305000, $done['forgiven_minor']);
        $this->assertSame(5000, $done['still_owed_minor']);
        $this->assertSame(5000, RentalCharge::where('lease_id', $lease->id)->where('kind', 'item')->firstOrFail()->left());
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_forgiven_part_of_a_deposit_is_not_money_held(): void
    {
        [$company, $owner, $lease, $bills] = $this->marchLease();
        // 100,000 of the 300,000 deposit was paid; the rest of the deposit bill is forgiven as the tenant leaves.
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 410000, 'method' => 'cash', 'split' => [['charge_id' => $bills['deposit']->id, 'amount_minor' => 100000],
            ['charge_id' => $bills['rent']->id, 'amount_minor' => 300000], ['charge_id' => $bills['item']->id, 'amount_minor' => 10000]]]);
        Carbon::setTestNow('2026-03-20 09:00:00');
        $done = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-20', 'refund_deposit' => 'cash'])['settlement'];
        $this->assertSame(100000, $done['deposit_refunded_minor']);
        $this->assertSame(0, Deposits::held($lease->fresh()));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_caretaker_cannot_refund_outside_their_properties(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 700000, 'method' => 'cash']);
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        $on = Carbon::parse('2026-03-01');
        $this->refused('not_found', fn () => app(Payments::class)->refundCredit($caretaker, $lease->fresh(), 1000, 'cash', $on));
        $this->refused('not_found', fn () => Deposits::refund($caretaker, $lease->fresh(), 1000, 'cash', $on));
    }

    public function test_an_ended_lease_takes_money_only_for_what_it_owes(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        Carbon::setTestNow('2026-03-20 09:00:00');
        app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-20']);
        $ended = $lease->fresh();
        $owed = Leases::position($ended)['owed_minor'];
        $this->assertGreaterThan(0, $owed);
        $pay = app(Payments::class);
        $pay->receive($owner, $ended, ['amount_minor' => $owed, 'method' => 'cash']);
        $this->refused('lease_not_live', fn () => $pay->receive($owner, $ended->fresh(), ['amount_minor' => 5000, 'method' => 'cash']));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_receipt_says_the_amount_in_words(): void
    {
        [$company, $owner, $lease] = $this->marchLease();
        $p = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 1250000, 'method' => 'cash']);
        $this->assertSame('One million two hundred and fifty thousand shillings only', Receipt::parts($p)['words']);
        $this->assertSame('Three hundred and five shillings only', AmountWords::of(305, (int) $company->id));
    }
}

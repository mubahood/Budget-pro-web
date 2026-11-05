<?php

namespace Tests\Feature\Rentals;

use App\Services\Rentals\CashBook;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Reports\MoneyPosition;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Whose money the cash is (LC-12): the journal's invariant makes it add up after every kind of posting, as of any day, for one account only.
 */
class RentalsMoneyPositionTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, int> $figures */
    private function assertPosition(array $figures, int $cid, string $on): void
    {
        $p = MoneyPosition::asOf($cid, $on);
        $this->assertSame($on, $p['as_of']);
        $this->assertSame($figures, array_intersect_key($p, $figures), 'as of '.$on);
        $this->assertSame($p['cash_minor'], $p['held_for_others_minor'] + $p['agency_own_minor'], 'the cash is all someone\'s');
        $this->assertSame($p['owed_to_landlords_minor'] - $p['owed_back_by_landlords_minor'] + $p['deposits_minor'] + $p['credit_minor'], $p['held_for_others_minor']);
    }

    public function test_it_adds_up_after_every_kind_of_posting(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 2, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 200000]);
        $landlord = $lease->landlord()->first();
        $this->assertPosition(['cash_minor' => 0, 'agency_own_minor' => 0, 'held_for_others_minor' => 0], $cid, '2026-03-01');

        // Opening cash and a landlord's opening balance: equity, then the agency's own less what it owed the landlord.
        app(CashBook::class)->opening($owner, 'cash', 1000000, Carbon::parse('2026-03-01'));
        LandlordAccounts::opening($owner, $landlord, 100000, Carbon::parse('2026-03-01'));
        $this->assertPosition(['cash_minor' => 1000000, 'owed_to_landlords_minor' => 100000, 'agency_own_minor' => 900000], $cid, '2026-03-01');

        // Rent, the deposit, and rent paid ahead: 300,000 rent at 10%, 200,000 deposit, 50,000 credit.
        $paid = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 550000, 'method' => 'cash', 'paid_on' => '2026-03-01']);
        $this->assertPosition(['cash_minor' => 1550000, 'owed_to_landlords_minor' => 370000, 'deposits_minor' => 200000, 'credit_minor' => 50000, 'agency_own_minor' => 930000], $cid, '2026-03-01');

        // Moving money changes no one's share.
        app(CashBook::class)->transfer($owner, 'cash', 'bank', 400000, '2026-03-01', 'Banked');
        $this->assertPosition(['cash_minor' => 1550000, 'agency_own_minor' => 930000], $cid, '2026-03-01');

        Carbon::setTestNow('2026-03-10 09:00:00');
        // A payout, an expense charged to the landlord, the agency's own.
        $payout = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 250000, 'method' => 'cash']);
        app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => 'Repairs', 'description' => 'Gate', 'amount_minor' => 20000, 'method' => 'cash']);
        app(Expenses::class)->record($owner, ['category' => 'Agency office', 'amount_minor' => 30000, 'method' => 'cash', 'charged_to' => 'agency']);
        $this->assertPosition(['cash_minor' => 1250000, 'owed_to_landlords_minor' => 100000, 'owed_back_by_landlords_minor' => 0, 'agency_own_minor' => 900000], $cid, '2026-03-10');

        // An advance: the landlord now owes, which lowers what is held for others and leaves the agency's own alone.
        app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 150000, 'method' => 'cash', 'advance' => true, 'advance_reason' => 'School fees']);
        $this->assertPosition(['cash_minor' => 1100000, 'owed_to_landlords_minor' => 0, 'owed_back_by_landlords_minor' => 50000, 'agency_own_minor' => 900000], $cid, '2026-03-10');

        // A reversal puts back what the payout took.
        app(Payouts::class)->reverse($owner, $payout, 'Typed against the wrong landlord');
        $this->assertPosition(['cash_minor' => 1350000, 'owed_to_landlords_minor' => 200000, 'owed_back_by_landlords_minor' => 0, 'agency_own_minor' => 900000], $cid, '2026-03-10');

        // Reversing the receipt takes back its commission too.
        app(Payments::class)->reverse($owner, $paid, 'Bounced');
        $this->assertPosition(['cash_minor' => 800000, 'owed_to_landlords_minor' => 0, 'owed_back_by_landlords_minor' => 70000, 'deposits_minor' => 0, 'credit_minor' => 0, 'agency_own_minor' => 870000], $cid, '2026-03-10');
        $this->assertBooksAgree($cid);
    }

    public function test_it_reads_any_day_and_only_its_own_account(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 300000, 'method' => 'cash', 'paid_on' => '2026-03-05']);

        $this->assertSame(0, MoneyPosition::asOf($cid, '2026-03-04')['cash_minor']);
        $this->assertSame(300000, MoneyPosition::asOf($cid, '2026-03-05')['cash_minor'], 'the whole of the day');
        $this->assertSame(270000, MoneyPosition::asOf($cid, '2026-12-31')['owed_to_landlords_minor']);

        [$other] = $this->rentalsAccount();
        $this->assertSame(0, MoneyPosition::asOf((int) $other->id, '2026-12-31')['cash_minor']);
    }

    public function test_spending_money_held_for_others_shows_as_the_agency_below_zero(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 300000, 'method' => 'cash', 'paid_on' => '2026-03-05']);
        app(Expenses::class)->record($owner, ['category' => 'Agency office', 'amount_minor' => 100000, 'method' => 'cash', 'charged_to' => 'agency']);

        $this->assertPosition(['cash_minor' => 200000, 'owed_to_landlords_minor' => 270000, 'agency_own_minor' => -70000], $cid, '2026-03-05');
    }
}

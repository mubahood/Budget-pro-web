<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalEntry;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\MoneyPosition;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * What the agency takes out of its own money (LC-13): cash −, equity −. It balances, the cash must be there, a reversal puts it back, its income statement
 * and every landlord's statement stay as they were, only an agency's owner or manager may, and a closed month refuses it.
 */
class RentalsAgencyDrawingTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An agency that has earned 30,000 commission on 300,000 of rent, all in cash: [company, owner, landlord]. */
    private function earning(): array
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 300000, 'method' => 'cash', 'paid_on' => '2026-03-05']);

        return [$company, $owner, $lease->landlord()->first()];
    }

    private function refused(callable $fn, string $code): void
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode());

            return;
        }
        $this->fail("Expected {$code}.");
    }

    public function test_it_takes_cash_and_equity_and_nothing_else(): void
    {
        [$company, $owner, $landlord] = $this->earning();
        $cid = (int) $company->id;
        $before = MoneyPosition::asOf($cid, '2026-03-05');
        $this->assertSame(30000, $before['agency_own_minor']);

        $row = app(CashBook::class)->agencyDrawing($owner, 'cash', 20000, '2026-03-05', 'October share');
        $this->assertSame('agency_drawing', $row->type);
        $this->assertSame([-20000, -20000, 0, 0, 0, 0, 0], [$row->cash_minor, $row->equity_minor, $row->landlord_minor, $row->deposit_minor, $row->credit_minor, $row->income_minor, $row->expense_minor]);
        $this->assertSame('Taken out by the agency: October share', $row->description);

        $after = MoneyPosition::asOf($cid, '2026-03-05');
        $this->assertSame([280000, 10000, 270000], [$after['cash_minor'], $after['agency_own_minor'], $after['owed_to_landlords_minor']]);
        $this->assertSame($after['cash_minor'], $after['held_for_others_minor'] + $after['agency_own_minor']);
        $this->assertSame(270000, LandlordAccounts::balance($landlord));
        $this->assertSame(1, DB::table('rental_activities')->where('company_id', $cid)->where('action', 'cash.agency_drawing')->count());
        $this->assertBooksAgree($cid);
    }

    public function test_the_cash_guard_applies_and_nothing_is_written(): void
    {
        [$company, $owner] = $this->earning();
        $cid = (int) $company->id;
        $this->refused(fn () => app(CashBook::class)->agencyDrawing($owner, 'bank', 1000), 'insufficient_cash');
        $this->refused(fn () => app(CashBook::class)->agencyDrawing($owner, 'cash', 300001, null, null, true), 'insufficient_cash');
        $this->refused(fn () => app(CashBook::class)->agencyDrawing($owner, 'petty', 1000), 'method_invalid');
        $this->refused(fn () => app(CashBook::class)->agencyDrawing($owner, 'cash', 0), 'amount_invalid');
        $this->refused(fn () => app(CashBook::class)->agencyDrawing($owner, 'cash', 1000, '2026-03-06'), 'date_in_future');
        $this->assertSame(0, RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('type', 'agency_drawing')->count());
    }

    public function test_going_below_its_own_money_needs_a_second_yes_and_never_a_block(): void
    {
        [$company, $owner] = $this->earning();
        $cid = (int) $company->id;
        $this->refused(fn () => app(CashBook::class)->agencyDrawing($owner, 'cash', 30001), 'agency_own_below_zero');
        app(CashBook::class)->agencyDrawing($owner, 'cash', 30000);
        $this->assertSame(0, MoneyPosition::asOf($cid, '2026-03-05')['agency_own_minor'], 'exactly its own needs no second yes');
        app(CashBook::class)->agencyDrawing($owner, 'cash', 5000, null, null, true);
        $position = MoneyPosition::asOf($cid, '2026-03-05');
        $this->assertSame(-5000, $position['agency_own_minor']);
        $this->assertSame($position['cash_minor'], $position['held_for_others_minor'] + $position['agency_own_minor']);
        $this->assertBooksAgree($cid);
    }

    public function test_a_reversal_puts_it_back_dated_today_with_a_reason_and_only_once(): void
    {
        [$company, $owner] = $this->earning();
        $cid = (int) $company->id;
        $row = app(CashBook::class)->agencyDrawing($owner, 'cash', 20000, '2026-03-05');
        $this->refused(fn () => app(CashBook::class)->reverseDrawing($owner, (int) $row->id, '  '), 'reason_required');

        Carbon::setTestNow('2026-03-09 09:00:00');
        $back = app(CashBook::class)->reverseDrawing($owner, (int) $row->id, 'Typed the wrong amount');
        $this->assertSame('reversal', $back->type);
        $this->assertSame('2026-03-09', $back->occurred_on->toDateString());
        $this->assertSame([20000, 20000], [$back->cash_minor, $back->equity_minor]);
        $this->assertNotNull($row->fresh()->reversed_at);
        $this->assertSame(30000, MoneyPosition::asOf($cid, '2026-03-09')['agency_own_minor']);
        $this->assertSame(10000, MoneyPosition::asOf($cid, '2026-03-08')['agency_own_minor'], 'as it stood before the correction');
        $this->refused(fn () => app(CashBook::class)->reverseDrawing($owner, (int) $row->id, 'Again'), 'already_reversed');

        $rows = CashBook::statement($cid, '2026-03-01', '2026-03-31')['rows'];
        $this->assertTrue(collect($rows)->firstWhere('id', (int) $row->id)['reversed'], 'the cash book says so');
        $this->assertBooksAgree($cid);
    }

    public function test_only_a_drawing_of_this_account_can_be_reversed_here(): void
    {
        [$company, $owner] = $this->earning();
        $other = RentalEntry::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'collected')->firstOrFail();
        $this->refused(fn () => app(CashBook::class)->reverseDrawing($owner, (int) $other->id, 'Not a drawing'), 'not_found');
        $row = app(CashBook::class)->agencyDrawing($owner, 'cash', 1000);

        [, $stranger] = $this->rentalsAccount();
        $this->refused(fn () => app(CashBook::class)->reverseDrawing($stranger, (int) $row->id, 'Not mine'), 'not_found');
        $this->assertNull($row->fresh()->reversed_at);
    }

    public function test_income_and_every_statement_stay_as_they_were(): void
    {
        [$company, $owner, $landlord] = $this->earning();
        $cid = (int) $company->id;
        $range = ['from' => '2026-03-01', 'to' => '2026-03-31'];
        $income = (new RentalReports)->run('income_statement', $cid, null, $range);
        $statement = LandlordAccounts::statement($landlord, '2026-03-01', '2026-03-31');

        app(CashBook::class)->agencyDrawing($owner, 'cash', 20000);

        $after = (new RentalReports)->run('income_statement', $cid, null, $range);
        $this->assertSame($income['totals'], $after['totals'], 'not income, not an expense');
        $this->assertSame('Taken out by the agency', $after['sections'][0]['title']);
        $this->assertSame(20000, $after['sections'][0]['rows'][0]['amount_minor']);
        $this->assertSame($statement['lines'], LandlordAccounts::statement($landlord, '2026-03-01', '2026-03-31')['lines']);
        $book = (new RentalReports)->run('cash_book', $cid, null, $range);
        $this->assertContains('Taken out by the agency', array_column($book['rows'], 'kind'));
    }

    public function test_owner_and_manager_may_and_no_one_else(): void
    {
        [$company, $owner] = $this->earning();
        foreach (['rentals_accountant', 'rentals_caretaker', 'viewer'] as $role) {
            $this->refused(fn () => app(CashBook::class)->agencyDrawing($this->rentalsStaff($company, $role), 'cash', 1000), 'forbidden');
        }
        $row = app(CashBook::class)->agencyDrawing($this->rentalsStaff($company, 'rentals_manager'), 'cash', 1000);
        $this->assertSame(-1000, $row->cash_minor);

        $this->refused(fn () => app(CashBook::class)->reverseDrawing($this->rentalsStaff($company, 'rentals_caretaker'), (int) $row->id, 'No'), 'forbidden');
        $back = app(CashBook::class)->reverseDrawing($this->rentalsStaff($company, 'rentals_accountant'), (int) $row->id, 'As any correction');
        $this->assertSame(1000, $back->cash_minor);
    }

    public function test_a_caretaker_given_the_permission_still_cannot(): void
    {
        [$company, $owner] = $this->earning();
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        foreach (['rentals_settings', 'rentals_reverse'] as $permission) {
            DB::table('company_role_permissions')->insert(['company_id' => $company->id, 'role' => 'rentals_caretaker', 'permission' => $permission, 'allowed' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        \App\Services\Team\Permissions::flush();
        $this->refused(fn () => app(CashBook::class)->agencyDrawing($caretaker, 'cash', 1000), 'forbidden');
        $this->assertSame(0, RentalEntry::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'agency_drawing')->count());
    }

    public function test_an_owner_run_account_has_none(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner');
        app(CashBook::class)->opening($owner, 'cash', 100000, Carbon::parse('2026-03-01'));
        $this->refused(fn () => app(CashBook::class)->agencyDrawing($owner, 'cash', 1000), 'agency_only');
        $this->assertTrue(RentalsModule::isOwnerMode((int) $company->id));
    }

    public function test_a_closed_month_refuses_it_and_today_is_fine(): void
    {
        [$company, $owner] = $this->earning();
        $cid = (int) $company->id;
        Carbon::setTestNow('2026-04-02 09:00:00');
        RentalsModule::settings($cid)->forceFill(['closed_through' => '2026-03-31'])->save();
        $this->refused(fn () => app(CashBook::class)->agencyDrawing($owner, 'cash', 1000, '2026-03-20'), 'period_closed');
        $this->assertSame(0, RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('type', 'agency_drawing')->count(), 'nothing written');
        $this->assertSame(0, DB::table('rental_activities')->where('company_id', $cid)->where('action', 'cash.agency_drawing')->count());

        $row = app(CashBook::class)->agencyDrawing($owner, 'cash', 1000, '2026-04-02');
        $this->assertSame('2026-04-02', $row->occurred_on->toDateString());
        // Its correction lands in the open month whatever month it belonged to.
        $back = app(CashBook::class)->reverseDrawing($owner, (int) $row->id, 'Wrong');
        $this->assertSame('2026-04-02', $back->occurred_on->toDateString());
        $this->assertBooksAgree($cid);
    }
}

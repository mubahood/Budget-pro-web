<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalPayout;
use App\Models\User;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Reports\LandlordWorkReports;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * An advance says what it is (LC-11): stored exactly, with a reason, with a second tick when the agency's own money does not cover it. It
 * clears as money is collected, a reversal restores it, the journal stays cash minus, landlord minus, and the report reads balances.
 */
class RentalsPayoutAdvancesTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A landlord owed 90,000 with 100,000 of cash, 500,000 of it opening money the agency owns. @return array{0: Company, 1: User, 2: RentalLandlord, 3: RentalLease} */
    private function setUpLandlord(): array
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$property, $units] = $this->building($owner, 1, 100000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        app(CashBook::class)->opening($owner, 'cash', 500000, Carbon::parse('2026-03-01'));

        return [$company, $owner, RentalLandlord::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($property->landlord_id), $lease];
    }

    private function pay(User $by, RentalLandlord $l, int $amount, array $more = []): RentalPayout
    {
        return app(Payouts::class)->record($by, $l, ['amount_minor' => $amount, 'method' => 'cash'] + $more);
    }

    private const AHEAD = ['advance' => true, 'advance_reason' => 'School fees'];

    public function test_the_amount_is_stored_exactly_on_a_positive_a_zero_and_a_negative_balance(): void
    {
        [$company, $owner, $landlord] = $this->setUpLandlord();
        $within = $this->pay($owner, $landlord, 40000);
        $this->assertSame(0, $within->advance_minor);
        $this->assertNull($within->advance_reason);

        $positive = $this->pay($owner, $landlord, 80000, self::AHEAD); // owed 50,000
        $this->assertSame(30000, $positive->advance_minor);
        $this->assertSame('School fees', $positive->advance_reason);
        $this->assertSame(-30000, $positive->balance_after_minor);

        $negative = $this->pay($owner, $landlord, 10000, self::AHEAD); // already below zero: all of it is ahead
        $this->assertSame(10000, $negative->advance_minor);
        $this->assertSame(-30000, $negative->balance_before_minor);

        app(Payouts::class)->reverse($owner, $negative, 'Typed twice');
        app(Payouts::class)->reverse($owner, $positive, 'Typed twice');
        $this->assertSame(50000, LandlordAccounts::balance($landlord));
        $zero = $this->pay($owner, $landlord, 50000);
        $this->assertSame(0, $zero->advance_minor);
        $atZero = $this->pay($owner, $landlord, 20000, self::AHEAD);
        $this->assertSame(20000, $atZero->advance_minor);

        // Its journal row is the normal one, and says what it is on the statement.
        $row = RentalEntry::withoutGlobalScopes()->where('company_id', $company->id)->where('payout_id', $atZero->id)->firstOrFail();
        $this->assertSame('payout', $row->type);
        $this->assertSame(-20000, (int) $row->cash_minor);
        $this->assertSame(-20000, (int) $row->landlord_minor);
        $this->assertSame('Payout '.$atZero->number.' (UGX 20,000 paid ahead)', $row->description);
        $lines = LandlordAccounts::statement($landlord, '2026-03-01', '2026-03-31')['lines'];
        $this->assertSame('Payout '.$atZero->number.' (UGX 20,000 paid ahead)', end($lines)['description']);
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_it_needs_the_tick_and_a_reason(): void
    {
        [$company, $owner, $landlord] = $this->setUpLandlord();
        $this->assertRefused('payout_above_balance', fn () => $this->pay($owner, $landlord, 90001));
        $this->assertRefused('advance_reason_required', fn () => $this->pay($owner, $landlord, 90001, ['advance' => true]));
        $this->assertRefused('advance_reason_required', fn () => $this->pay($owner, $landlord, 90001, ['advance' => true, 'advance_reason' => '   ']));
        $this->assertSame(0, RentalPayout::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_more_than_the_agencys_own_money_warns_and_passes_on_a_second_tick(): void
    {
        [$company, $owner, $landlord] = $this->setUpLandlord(); // the agency's own: 500,000 opening + 10,000 commission
        $cid = (int) $company->id;
        $this->assertSame(0, Payouts::fromOthers($cid, 510000));
        $this->assertSame(1, Payouts::fromOthers($cid, 510001));

        // A second landlord's money is what would pay for it.
        [$otherProperty, $otherUnits] = $this->building($owner, 1, 1000000);
        app(Payments::class)->receive($owner, $this->tenantIn($owner, $otherUnits[0], '2026-03-01', ['deposit_minor' => 0]), ['amount_minor' => 1000000, 'method' => 'cash']);
        $own = \App\Services\Rentals\Reports\MoneyPosition::asOf($cid, '2026-03-05')['agency_own_minor'];
        $this->assertSame(610000, $own, '500,000 opening and 110,000 commission');
        try {
            $this->pay($owner, $landlord, 90000 + $own + 5000, self::AHEAD);
            $this->fail('Should warn.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('advance_from_others', $e->errorCode());
            $this->assertSame(5000, $e->meta()['others_minor']);
            $this->assertSame('UGX 5,000 of this would come from money held for other landlords or tenants\' deposits.', $e->getMessage());
        }
        $p = $this->pay($owner, $landlord, 90000 + $own + 5000, self::AHEAD + ['advance_others_ok' => true]);
        $this->assertSame($own + 5000, $p->advance_minor);
        $this->assertNotNull($otherProperty);
        $this->assertBooksAgree($cid);
    }

    public function test_an_owner_drawing_words_it_for_an_owner(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner');
        [, $units] = $this->building($owner, 1, 100000);
        app(Payments::class)->receive($owner, $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 100000]), ['amount_minor' => 200000, 'method' => 'cash']);
        $self = \App\Services\Rentals\RentalsModule::selfLandlord((int) $company->id);
        try {
            $this->pay($owner, $self, 150000, self::AHEAD);
            $this->fail('Should warn.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('advance_from_others', $e->errorCode());
            $this->assertStringContainsString('tenants\' deposits or rent they paid ahead', $e->getMessage());
            $this->assertStringNotContainsString('landlord', $e->getMessage());
        }
        $p = $this->pay($owner, $self, 150000, self::AHEAD + ['advance_others_ok' => true]);
        $this->assertSame(50000, $p->advance_minor);
        $this->assertSame('Owner drawing '.$p->number.' (UGX 50,000 taken ahead)', RentalEntry::withoutGlobalScopes()->where('payout_id', $p->id)->value('description'));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_recovery_clears_it_and_a_reversal_restores_it(): void
    {
        [$company, $owner, $landlord, $lease] = $this->setUpLandlord();
        $p = $this->pay($owner, $landlord, 490000, self::AHEAD); // owed 90,000, 400,000 ahead
        $this->assertSame(400000, $p->advance_minor);
        $this->assertSame(-400000, LandlordAccounts::balance($landlord));

        // The next collection lowers it, with no schedule: 500,000 rent at 10% is 450,000 to the landlord.
        Carbon::setTestNow('2026-03-06 09:00:00');
        $lease->forceFill(['rent_minor' => 100000])->save();
        app(\App\Services\Rentals\Billing\Billing::class)->charge($owner, $lease->fresh(), ['kind' => 'one_off', 'description' => 'Back rent', 'amount_minor' => 450000 + 50000, 'due_date' => '2026-03-06', 'goes_to' => 'landlord', 'commissionable' => false]);
        app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 450000, 'method' => 'cash']);
        $this->assertSame(50000, LandlordAccounts::balance($landlord));

        app(Payouts::class)->reverse($owner, $p, 'Paid to the wrong person');
        $this->assertSame(540000, LandlordAccounts::balance($landlord));
        $this->assertSame(400000, (int) $p->fresh()->advance_minor, 'what it was stays on the row');
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_the_report_reads_balances_so_old_rows_count_and_a_new_landlord_is_not_averaged_over_empty_months(): void
    {
        Carbon::setTestNow('2026-01-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 1, 100000);
        $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($property->landlord_id);
        $lease = $this->tenantIn($owner, $units[0], '2026-01-01', ['deposit_minor' => 0]);
        $billing = app(\App\Services\Rentals\Billing\Billing::class);
        app(CashBook::class)->opening($owner, 'cash', 5000000, Carbon::parse('2026-01-01'));
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        $this->assertSame([], LandlordWorkReports::advances($cid), 'nobody is below zero');

        // Paid ahead on 10 January, an old row with no advance column.
        Carbon::setTestNow('2026-01-10 09:00:00');
        $old = $this->pay($owner, $landlord, 450000, self::AHEAD);
        DB::table('rental_payouts')->where('id', $old->id)->update(['advance_minor' => 0, 'advance_reason' => null]);

        foreach (['2026-02-01', '2026-03-01'] as $day) {
            Carbon::setTestNow($day.' 09:00:00');
            $billing->raiseAll($cid);
            app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 100000, 'method' => 'cash']);
        }
        Carbon::setTestNow('2026-04-15 09:00:00');

        $rows = LandlordWorkReports::advances($cid);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $landlord->id, $rows[0]['landlord_id']);
        $this->assertSame(180000, $rows[0]['owes_minor']); // 450,000 − 90,000 × 3
        $this->assertSame('2026-01-10', $rows[0]['since']);
        $this->assertSame('2026-03-01', $rows[0]['last_collected_on']);
        $this->assertSame(90000, $rows[0]['average_minor'], 'January to March: three months on the books, 270,000');
        $this->assertSame(2, $rows[0]['months_to_clear']);

        $report = (new RentalReports)->run('landlord_advances', $cid, $owner);
        $this->assertSame('About 2 months', $report['rows'][0]['clears']);
        $this->assertSame(180000, $report['totals']['owes_minor']);
        $this->assertStringStartsWith('%PDF', RentalExport::pdf($report, $cid));

        // Back above zero and below again: since is the latest crossing. Only what is asked for is read.
        $this->assertSame([], LandlordWorkReports::advances($cid, [(int) $landlord->id + 999]));
        $this->assertBooksAgree($cid);
    }

    public function test_the_report_refuses_a_caretaker_and_an_owner_run_account(): void
    {
        [$company] = $this->setUpLandlord();
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        $this->assertRefused('forbidden', fn () => (new RentalReports)->run('landlord_advances', (int) $company->id, $caretaker));
        [$ownerCompany, $selfOwner] = $this->rentalsAccount('owner');
        $this->assertRefused('agency_only', fn () => (new RentalReports)->run('landlord_advances', (int) $ownerCompany->id, $selfOwner));
    }

    private function assertRefused(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());

            return;
        }
        $this->fail('Expected a refusal: '.$code);
    }
}

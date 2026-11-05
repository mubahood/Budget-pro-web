<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalPayout;
use App\Models\RentalPayoutRun;
use App\Models\RentalPayoutRunLine;
use App\Models\RentalProperty;
use App\Models\User;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\PayoutRuns;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Reports\RentalExport;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Paying many landlords at once (LC-09), through the services: every line becomes a normal payout or none does, a balance or account that
 * changed after the draft refuses the whole run naming the rows, it cannot post twice, and each payout reverses on its own.
 */
class RentalsPayoutRunTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Two landlords with money collected: A is owed 270,000 and B 540,000, in cash. @return array{0: Company, 1: User, 2: RentalLandlord, 3: RentalLandlord, 4: list<\App\Models\RentalLease>} */
    private function twoLandlords(): array
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$pa, $ua] = $this->building($owner, 1, 300000);
        [$pb, $ub] = $this->building($owner, 1, 600000);
        $la = $this->tenantIn($owner, $ua[0], '2026-03-01', ['deposit_minor' => 0]);
        $lb = $this->tenantIn($owner, $ub[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $la, ['amount_minor' => 300000, 'method' => 'cash']);
        app(Payments::class)->receive($owner, $lb, ['amount_minor' => 600000, 'method' => 'cash']);
        $find = fn (RentalProperty $p) => RentalLandlord::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($p->landlord_id);

        return [$company, $owner, $find($pa), $find($pb), [$la, $lb]];
    }

    private function runs(): PayoutRuns
    {
        return app(PayoutRuns::class);
    }

    public function test_it_lists_who_is_owed_and_a_run_posts_as_one_payout_each(): void
    {
        [$company, $owner, $a, $b] = $this->twoLandlords();
        $cid = (int) $company->id;

        $list = $this->runs()->candidates($owner);
        $this->assertSame([270000, 540000], collect($list)->sortBy('balance_minor')->pluck('balance_minor')->values()->all());
        $this->assertSame([(int) $b->id], array_column($this->runs()->candidates($owner, 300000), 'landlord_id'), 'owed more than a chosen amount');

        app(CashBook::class)->transfer($owner, 'cash', 'bank', 500000, '2026-03-05', 'Banked');
        $cashBefore = RentalLedger::cash($cid);
        $run = $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 100000, 'method' => 'cash'], ['landlord_id' => $b->id, 'amount_minor' => 500000, 'method' => 'bank']]);
        $this->assertSame('draft', $run->status);
        $this->assertStringStartsWith('PR-', $run->number);
        $this->assertSame(0, RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->count(), 'a draft pays nobody');

        $posted = $this->runs()->post($owner, $run, ['paid_on' => '2026-03-05', 'reference' => 'BATCH-1', 'references' => [RentalPayoutRunLine::withoutGlobalScopes()->where('run_id', $run->id)->where('landlord_id', $b->id)->value('id') => 'TT-777']]);
        $this->assertSame('posted', $posted->status);
        $this->assertSame('2026-03-05', $posted->paid_on->toDateString());
        $this->assertSame((int) $owner->id, (int) $posted->posted_by_id);

        $payouts = RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->where('run_id', $run->id)->orderBy('amount_minor')->get();
        $this->assertSame([100000, 500000], $payouts->pluck('amount_minor')->all());
        $this->assertSame(['BATCH-1', 'TT-777'], $payouts->pluck('reference')->all());
        $this->assertSame([270000, 540000], $payouts->pluck('balance_before_minor')->all());
        $this->assertSame([170000, 40000], $payouts->pluck('balance_after_minor')->all());
        // Sum of the run, of the payout rows and of the cash that left are one figure, one journal group each.
        $rows = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('type', 'payout')->get();
        $this->assertSame(600000, -(int) $rows->sum('cash_minor'));
        $this->assertSame(600000, (int) RentalPayoutRunLine::withoutGlobalScopes()->where('run_id', $run->id)->sum('amount_minor'));
        $this->assertSame(600000, $cashBefore['total'] - RentalLedger::cash($cid)['total']);
        $this->assertCount(2, $rows->pluck('group_id')->unique());
        $this->assertSame(170000, LandlordAccounts::balance($a));
        $this->assertSame(40000, LandlordAccounts::balance($b));
        $this->assertBooksAgree($cid);
    }

    public function test_a_balance_changed_after_the_draft_refuses_the_whole_run_and_names_the_row(): void
    {
        [$company, $owner, $a, $b, $leases] = $this->twoLandlords();
        $cid = (int) $company->id;
        $run = $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 100000, 'method' => 'cash'], ['landlord_id' => $b->id, 'amount_minor' => 100000, 'method' => 'cash']]);

        app(Payments::class)->receive($owner, $leases[0]->fresh(), ['amount_minor' => 10000, 'method' => 'cash']); // credit: no balance moves
        $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 100000, 'method' => 'cash']]); // another draft is no change either
        app(Payouts::class)->record($owner, $a, ['amount_minor' => 1000, 'method' => 'cash']);
        $number = (string) RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->max('number');
        try {
            $this->runs()->post($owner, $run);
            $this->fail('A changed balance must refuse the run.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('run_changed', $e->errorCode());
            $this->assertArrayHasKey((int) $a->id, $e->meta()['rows']);
            $this->assertArrayNotHasKey((int) $b->id, $e->meta()['rows']);
            $this->assertStringContainsString($a->name, $e->getMessage());
        }
        $this->assertSame('draft', $run->fresh()->status);
        $this->assertSame(1, RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->count(), 'nothing written');
        $this->assertSame($number, (string) RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->max('number'));

        // Saving the draft again takes today's balances, and then it posts.
        $again = $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 100000, 'method' => 'cash'], ['landlord_id' => $b->id, 'amount_minor' => 100000, 'method' => 'cash']], $run);
        $this->assertSame((int) $run->id, (int) $again->id);
        $this->assertSame('posted', $this->runs()->post($owner, $again)->status);
        $this->assertBooksAgree($cid);
    }

    public function test_a_short_account_refuses_with_the_guards_own_words(): void
    {
        [$company, $owner, $a, $b] = $this->twoLandlords();
        $cid = (int) $company->id;
        $lines = [['landlord_id' => $a->id, 'amount_minor' => 100000, 'method' => 'bank'], ['landlord_id' => $b->id, 'amount_minor' => 100000, 'method' => 'cash']];
        $check = PayoutRuns::check($cid, $lines);
        $this->assertTrue($check['short']);
        $this->assertSame(200000, $check['total_minor']);
        $byMethod = collect($check['accounts'])->keyBy('method');
        $this->assertStringContainsString('is recorded in Bank', (string) $byMethod['bank']['short']);
        $this->assertNull($byMethod['cash']['short']);
        $this->assertSame(800000, $byMethod['cash']['left_minor']);
        $this->assertSame(90000, $check['agency_own_minor'], 'commission: a run leaves the agency\'s own money as it was');

        $run = $this->runs()->save($owner, $lines);
        try {
            $this->runs()->post($owner, $run);
            $this->fail('A short account must refuse the run.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('run_changed', $e->errorCode());
            $this->assertArrayHasKey((int) $a->id, $e->meta()['rows']);
            $this->assertArrayNotHasKey((int) $b->id, $e->meta()['rows']);
            $this->assertStringContainsString('Move money', $e->getMessage());
        }
        $this->assertSame(0, RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->count());
    }

    public function test_a_future_or_closed_date_refuses_and_writes_nothing(): void
    {
        [$company, $owner, $a] = $this->twoLandlords();
        $cid = (int) $company->id;
        $run = $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 100000, 'method' => 'cash']]);
        $this->assertRefused('date_in_future', fn () => $this->runs()->post($owner, $run, ['paid_on' => '2026-03-06']));

        Carbon::setTestNow('2026-04-02 09:00:00');
        app(MonthClose::class)->close($owner, '2026-03');
        $seq = (int) \App\Services\Rentals\RentalsModule::settings($cid)->seq_payout;
        $this->assertRefused('period_closed', fn () => $this->runs()->post($owner, $run, ['paid_on' => '2026-03-20']));
        $this->assertSame(0, RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame('draft', $run->fresh()->status);
        $this->assertSame($seq, (int) \App\Services\Rentals\RentalsModule::settings($cid)->seq_payout, 'the number was not used up');

        $this->assertSame('posted', $this->runs()->post($owner, $run, ['paid_on' => '2026-04-02'])->status);
        $this->assertBooksAgree($cid);
    }

    public function test_posting_twice_is_one_set_and_a_cancelled_draft_leaves_nothing(): void
    {
        [$company, $owner, $a, $b] = $this->twoLandlords();
        $cid = (int) $company->id;
        $run = $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 100000, 'method' => 'cash']]);
        $this->runs()->post($owner, $run);
        $this->assertRefused('run_not_draft', fn () => $this->runs()->post($owner, $run->fresh()));
        $this->assertRefused('run_not_draft', fn () => $this->runs()->cancel($owner, $run->fresh()));
        $this->assertRefused('run_not_draft', fn () => $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 1000, 'method' => 'cash']], $run->fresh()));
        $this->assertSame(1, RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->count());

        $other = $this->runs()->save($owner, [['landlord_id' => $b->id, 'amount_minor' => 100000, 'method' => 'cash']]);
        $this->assertSame('cancelled', $this->runs()->cancel($owner, $other)->status);
        $this->assertRefused('run_not_draft', fn () => $this->runs()->post($owner, $other->fresh()));
        $this->assertSame(1, RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame(540000, LandlordAccounts::balance($b));
        $this->assertBooksAgree($cid);
    }

    public function test_each_payout_of_a_run_reverses_on_its_own(): void
    {
        [$company, $owner, $a, $b] = $this->twoLandlords();
        $cid = (int) $company->id;
        $run = $this->runs()->post($owner, $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 270000, 'method' => 'cash'], ['landlord_id' => $b->id, 'amount_minor' => 540000, 'method' => 'cash']]));
        $first = RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->where('run_id', $run->id)->where('landlord_id', $a->id)->firstOrFail();

        app(Payouts::class)->reverse($owner, $first, 'Sent to the wrong account');

        $this->assertSame(270000, LandlordAccounts::balance($a));
        $this->assertSame(0, LandlordAccounts::balance($b));
        $this->assertSame('posted', $run->fresh()->status);
        $this->assertNull(RentalPayout::withoutGlobalScopes()->where('run_id', $run->id)->where('landlord_id', $b->id)->value('reversed_at'));
        $this->assertBooksAgree($cid);
    }

    public function test_a_draft_refuses_above_the_balance_a_duplicate_nobody_and_a_bad_method(): void
    {
        [, $owner, $a, $b] = $this->twoLandlords();
        try {
            $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 270001, 'method' => 'cash'], ['landlord_id' => $b->id, 'amount_minor' => 1000, 'method' => 'cash']]);
            $this->fail('An advance is never part of a run.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('run_lines_invalid', $e->errorCode());
            $this->assertSame([(int) $a->id], array_keys($e->meta()['rows']));
        }
        $this->assertRefused('run_duplicate', fn () => $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 1000, 'method' => 'cash'], ['landlord_id' => $a->id, 'amount_minor' => 1000, 'method' => 'bank']]));
        $this->assertRefused('run_empty', fn () => $this->runs()->save($owner, []));
        $this->assertRefused('run_lines_invalid', fn () => $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 1000, 'method' => 'barter']]));
        $this->assertRefused('run_lines_invalid', fn () => $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => '1000', 'method' => 'cash']]));
        $this->assertSame(0, RentalPayoutRun::withoutGlobalScopes()->where('company_id', $owner->company_id)->count());
    }

    public function test_who_may_run_it_another_account_and_owner_mode(): void
    {
        [$company, $owner, $a] = $this->twoLandlords();
        $run = $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 1000, 'method' => 'cash']]);

        $viewer = $this->rentalsStaff($company, 'viewer');
        $this->assertRefused('forbidden', fn () => $this->runs()->candidates($viewer));
        $this->assertRefused('forbidden', fn () => $this->runs()->post($viewer, $run));

        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        $this->assertRefused('forbidden', fn () => $this->runs()->candidates($caretaker));
        $accountant = $this->rentalsStaff($company, 'rentals_accountant');
        $this->assertCount(2, $this->runs()->candidates($accountant), 'an accountant may');

        [, $stranger] = $this->rentalsAccount();
        [$strangerProperty] = $this->building($stranger, 1, 100000);
        $this->assertRefused('not_found', fn () => $this->runs()->post($stranger, $run));
        $this->assertRefused('not_found', fn () => $this->runs()->cancel($stranger, $run));
        $this->assertRefused('not_found', fn () => $this->runs()->save($stranger, [['landlord_id' => $a->id, 'amount_minor' => 1000, 'method' => 'cash']]));
        $this->assertSame([], $this->runs()->candidates($stranger));
        $this->assertNotNull($strangerProperty);

        [, $selfOwner] = $this->rentalsAccount('owner');
        $this->assertRefused('agency_only', fn () => $this->runs()->candidates($selfOwner));
    }

    public function test_an_inactive_landlord_on_a_draft_stops_the_run(): void
    {
        [$company, $owner, $a, $b] = $this->twoLandlords();
        $run = $this->runs()->save($owner, [['landlord_id' => $b->id, 'amount_minor' => 1000, 'method' => 'cash']]);
        RentalProperty::withoutGlobalScopes()->where('company_id', $company->id)->where('landlord_id', $b->id)->update(['status' => 'archived']);
        app(Landlords::class)->update($owner, $b, ['status' => 'inactive', 'inactive_reason' => 'Sold up']);
        try {
            $this->runs()->post($owner, $run);
            $this->fail('A landlord who left must stop the run.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('run_changed', $e->errorCode());
            $this->assertArrayHasKey((int) $b->id, $e->meta()['rows']);
        }
        $this->assertNotContains((int) $b->id, array_column($this->runs()->candidates($owner), 'landlord_id'));
        $this->assertContains((int) $a->id, array_column($this->runs()->candidates($owner), 'landlord_id'));
    }

    public function test_the_sheet_prints_exports_and_gives_cash_rows_room_to_sign(): void
    {
        [$company, $owner, $a, $b] = $this->twoLandlords();
        $cid = (int) $company->id;
        $a->forceFill(['payout_details' => 'Mobile 0772 000111'])->save();
        app(CashBook::class)->transfer($owner, 'cash', 'bank', 200000, '2026-03-05', 'Banked');
        $run = $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 100000, 'method' => 'cash'], ['landlord_id' => $b->id, 'amount_minor' => 200000, 'method' => 'bank']]);

        $sheet = PayoutRuns::sheet($run);
        $this->assertStringContainsString('Draft', $sheet['subtitle']);
        $this->assertSame(300000, $sheet['totals']['amount_minor']);
        $rows = collect($sheet['rows'])->keyBy('landlord');
        $this->assertStringContainsString('___', $rows[$a->name]['signature']);
        $this->assertSame('Not needed', $rows[$b->name]['signature']);
        $this->assertSame('Mobile 0772 000111', $rows[$a->name]['details']);
        $this->assertStringStartsWith('%PDF', RentalExport::pdf($sheet, $cid));
        $csv = RentalExport::csv($sheet, $cid);
        $this->assertStringContainsString($a->name, $csv);
        $this->assertStringContainsString('Payment details', $csv);
        $this->assertStringStartsWith('PK', RentalExport::xlsx($sheet, $cid));

        $this->runs()->post($owner, $run);
        $a->forceFill(['payout_details' => 'Changed later'])->save();
        $after = collect(PayoutRuns::sheet($run->fresh())['rows'])->keyBy('landlord');
        $this->assertSame('Mobile 0772 000111', $after[$a->name]['details'], 'a paid run keeps where the money went');
    }

    public function test_the_books_stay_true_with_an_expense_between_draft_and_post(): void
    {
        [$company, $owner, $a] = $this->twoLandlords();
        $property = RentalProperty::withoutGlobalScopes()->where('company_id', $company->id)->where('landlord_id', $a->id)->firstOrFail();
        $run = $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 270000, 'method' => 'cash']]);
        app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => 'Repairs', 'description' => 'Gate', 'amount_minor' => 5000, 'method' => 'cash']);
        $this->assertRefused('run_changed', fn () => $this->runs()->post($owner, $run));
        $this->assertRefused('run_lines_invalid', fn () => $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 270000, 'method' => 'cash']], $run));
        $this->runs()->post($owner, $this->runs()->save($owner, [['landlord_id' => $a->id, 'amount_minor' => 265000, 'method' => 'cash']], $run));
        $this->assertSame(0, LandlordAccounts::balance($a));
        $this->assertBooksAgree((int) $company->id);
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

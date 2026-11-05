<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalActivity;
use App\Models\RentalCharge;
use App\Models\RentalClose;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalPaidReport;
use App\Models\RentalPayment;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Demo\RentalsDemo;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Import\RentalImport;
use App\Services\Rentals\Jobs\RentalCheck;
use App\Services\Rentals\Jobs\RentalDaily;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Portal\PaidReports;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * The lock (LC-02, D7): a closed month takes no more money by any path, a correction lands in the open month, months close in order, only the owner reopens and only the latest,
 * and the nightly jobs, the phone, imports and the demo's upkeep all behave. Every test ends with rentals:check finding nothing.
 */
class RentalsMonthCloseTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function refused(string $code, callable $fn): BusinessRuleException
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());

            return $e;
        }
        $this->fail("Expected refusal {$code}");
    }

    /** An agency with a lease paid in October, October closed, the clock on 3 November. */
    private function octoberClosed(): object
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$property, $units] = $this->building($owner, 3, 400000);
        $lease = $this->tenantIn($owner, $units[0], '2026-10-01', ['deposit_minor' => 200000]);
        $pay = app(Payments::class);
        Carbon::setTestNow('2026-10-02 09:00:00');
        $pay->receive($owner, $lease, ['amount_minor' => 600000, 'method' => 'cash', 'paid_on' => '2026-10-02']);
        Carbon::setTestNow('2026-11-03 09:00:00');
        app(MonthClose::class)->close($owner, '2026-10');

        return (object) ['company' => $company, 'cid' => (int) $company->id, 'owner' => $owner, 'property' => $property, 'units' => $units, 'lease' => $lease->fresh(), 'pay' => $pay,
            'landlord' => RentalLandlord::withoutGlobalScopes()->findOrFail($property->landlord_id)];
    }

    /** @return array<string, int> */
    private function counts(int $cid): array
    {
        $out = [];
        foreach (['rental_entries', 'rental_payments', 'rental_allocations', 'rental_payouts', 'rental_expenses', 'rental_activities'] as $t) {
            $out[$t] = DB::table($t)->where('company_id', $cid)->count();
        }

        return $out;
    }

    public function test_money_dated_in_a_closed_month_is_refused_with_nothing_written_and_the_words_say_what_to_do(): void
    {
        $b = $this->octoberClosed();
        $on = Carbon::parse('2026-10-20');
        app(RentalDaily::class)->run($b->cid);
        // November paid with some credit left, so a refund has to be stopped by its date; and another tenant's open bill, for the waiver.
        $b->pay->receive($b->owner, $b->lease->fresh(), ['amount_minor' => 405000, 'method' => 'cash', 'paid_on' => '2026-11-03']);
        $this->assertSame(5000, Payments::credit($b->lease));
        $other = $this->tenantIn($b->owner, $b->units[1], '2026-11-01');
        $bill = RentalCharge::where('lease_id', $other->id)->where('status', 'open')->firstOrFail();
        $b->lease = $b->lease->fresh();
        $before = $this->counts($b->cid);
        $paths = [
            'receipt' => fn () => $b->pay->receive($b->owner, $b->lease, ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-20']),
            'payout' => fn () => app(Payouts::class)->record($b->owner, $b->landlord, ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-20']),
            'expense' => fn () => app(Expenses::class)->record($b->owner, ['amount_minor' => 1000, 'method' => 'cash', 'category' => 'Repairs', 'paid_on' => '2026-10-20', 'charged_to' => 'landlord', 'property_id' => $b->property->id]),
            'agency expense' => fn () => app(Expenses::class)->record($b->owner, ['amount_minor' => 1000, 'method' => 'cash', 'category' => 'Agency office', 'paid_on' => '2026-10-20', 'charged_to' => 'agency']),
            'transfer' => fn () => app(CashBook::class)->transfer($b->owner, 'cash', 'bank', 1000, '2026-10-20'),
            'opening cash' => fn () => app(CashBook::class)->opening($b->owner, 'bank', 1000, $on),
            'opening landlord' => fn () => LandlordAccounts::opening($b->owner, $b->landlord, 1000, $on),
            'opening credit' => fn () => $b->pay->openingCredit($b->lease, 1000, $on, $b->owner->id),
            'refund of credit' => fn () => $b->pay->refundCredit($b->owner, $b->lease, 1000, 'cash', $on),
            'deposit refund' => fn () => Deposits::refund($b->owner, $b->lease, 1000, 'cash', $on),
            'waiver' => fn () => $b->pay->waive($b->owner, $bill, 1000, 'Forgiven', $on),
            'move-out' => fn () => app(Leases::class)->moveOut($b->owner, $b->lease, ['date' => '2026-10-20', 'refund_deposit' => 'cash', 'reason' => 'Left']),
            'reversal row' => fn () => RentalLedger::locked($b->cid, fn () => RentalLedger::reverse($b->cid, [], '2026-10-20', 'x', $b->owner->id)),
        ];
        foreach ($paths as $name => $path) {
            $e = $this->refused('period_closed', $path);
            $this->assertSame('October is closed. Date this today, and it will show on November\'s statement, marked "For October". Or ask the account owner to reopen October.', $e->getMessage(), $name);
            $this->assertSame(['closed_through' => '2026-10-31', 'month' => '2026-10', 'use_date' => '2026-11-03', 'mark' => 'For October'], $e->meta(), $name);
            $this->assertSame($before, $this->counts($b->cid), "{$name}: nothing written");
        }
        $this->assertBooksAgree($b->cid);
    }

    public function test_the_same_money_dated_today_posts_and_a_late_receipt_is_marked_for_its_month(): void
    {
        $b = $this->octoberClosed();
        $today = '2026-11-03';
        app(RentalDaily::class)->run($b->cid); // November's bill: late money settles it, and a statement line says whose month it was
        $p = $b->pay->receive($b->owner, $b->lease, ['amount_minor' => 50000, 'method' => 'cash', 'paid_on' => $today, 'notes' => 'For October']);
        $this->assertSame($today, $p->paid_on->toDateString());
        app(Payouts::class)->record($b->owner, $b->landlord, ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => $today, 'notes' => 'For October']);
        app(Expenses::class)->record($b->owner, ['amount_minor' => 1000, 'method' => 'cash', 'category' => 'Repairs', 'description' => 'For October', 'paid_on' => $today, 'charged_to' => 'landlord', 'property_id' => $b->property->id]);
        app(CashBook::class)->transfer($b->owner, 'cash', 'bank', 1000, $today);
        $st = LandlordAccounts::statement($b->landlord, '2026-11-01', '2026-11-30');
        $marked = array_filter($st['lines'], fn ($l) => str_contains($l['description'], 'For October'));
        $this->assertCount(3, $marked, 'the receipt, the payout and the expense all say which month they belong to');
        $this->assertSame('For October', RentalLedger::lateMark('2026-10-30', Carbon::parse($today)));
        $this->assertSame('For December', RentalLedger::lateMark('2026-12-30', Carbon::parse('2027-01-03')));
        $this->assertSame('For October 2025', RentalLedger::lateMark('2025-10-30', Carbon::parse($today)));
        $this->assertBooksAgree($b->cid);
    }

    public function test_a_reversal_of_a_closed_receipt_lands_today_and_the_issued_statement_does_not_move(): void
    {
        $b = $this->octoberClosed();
        $issued = StatementBook::current($b->landlord, '2026-10');
        $proof = $issued->proof;
        $receipt = RentalPayment::where('company_id', $b->cid)->where('method', 'cash')->firstOrFail();
        $b->pay->reverse($b->owner, $receipt, 'The cheque bounced');

        $copy = $issued->fresh();
        $this->assertSame($proof, $copy->proof);
        $this->assertSame((int) $issued->closing_minor, (int) StatementBook::read($b->landlord, '2026-10-01', '2026-10-31')['closing_minor'], 'the paper stands');
        $this->assertSame((int) $issued->closing_minor, LandlordAccounts::balance($b->landlord, '2026-10-31'), 'and so does the journal to that day');
        $nov = StatementBook::read($b->landlord, '2026-11-01', '2026-11-30');
        $correction = collect($nov['lines'])->firstWhere('type', 'reversal');
        $this->assertNotNull($correction);
        $this->assertSame('2026-11-03', $correction['date']);
        $this->assertStringStartsWith('Correction', $correction['description']);
        $this->assertNotEmpty($nov['corrections']);
        $this->assertSame('2026-10-02', $nov['corrections'][0]['corrects']);
        $this->assertBooksAgree($b->cid);
    }

    public function test_months_close_once_over_in_order_and_the_first_close_can_start_anywhere(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $close = app(MonthClose::class);
        $this->refused('month_not_over', fn () => $close->close($owner, '2026-11'));
        $this->refused('month_not_over', fn () => $close->close($owner, '2026-12'));
        $this->refused('date_invalid', fn () => $close->close($owner, '2026-13'));

        $this->assertSame('2026-10', MonthClose::nextMonth($cid), 'with nothing closed, the month just ended');
        $close->close($owner, '2026-08');
        $this->assertSame('2026-08-31', MonthClose::closedThrough($cid));
        $this->refused('month_out_of_order', fn () => $close->close($owner, '2026-10'));
        $this->assertSame('2026-09', MonthClose::nextMonth($cid));
        $this->refused('month_already_closed', fn () => $close->close($owner, '2026-08'));
        $this->refused('month_already_closed', fn () => $close->close($owner, '2026-07'));
        $close->close($owner, '2026-09');
        $close->close($owner, '2026-10');
        $this->assertNull(MonthClose::nextMonth($cid), 'November has not ended');
        $this->assertSame(3, RentalClose::where('company_id', $cid)->count());
        $this->assertBooksAgree($cid);
    }

    public function test_a_close_needs_the_permission_and_books_that_agree(): void
    {
        $b = $this->octoberClosed();
        $close = app(MonthClose::class);
        foreach (['viewer', 'rentals_caretaker'] as $role) {
            $this->refused('forbidden', fn () => $close->close($this->rentalsStaff($b->company, $role), '2026-11'));
        }
        Carbon::setTestNow('2026-12-02 09:00:00');
        foreach (['viewer', 'rentals_caretaker'] as $role) {
            $this->refused('forbidden', fn () => $close->close($this->rentalsStaff($b->company, $role), '2026-11'));
        }
        // Books that disagree block closing, and nothing is written.
        DB::table('rental_charges')->where('company_id', $b->cid)->where('kind', 'rent')->limit(1)->update(['paid_minor' => 1]);
        $e = $this->refused('books_disagree', fn () => $close->close($b->owner, '2026-11'));
        $this->assertSame('charge_paid_cache', $e->meta()['problems'][0]['check']);
        $this->assertSame('2026-10-31', MonthClose::closedThrough($b->cid));
        $this->assertSame(1, RentalClose::where('company_id', $b->cid)->count());
        DB::table('rental_charges')->where('company_id', $b->cid)->where('kind', 'rent')->update(['paid_minor' => DB::raw('(SELECT COALESCE(SUM(a.amount_minor),0) FROM rental_allocations a WHERE a.charge_id = rental_charges.id)')]);

        // Manager and accountant may close.
        foreach (['rentals_manager', 'rentals_accountant'] as $i => $role) {
            Carbon::setTestNow(Carbon::parse('2026-12-02 09:00:00')->addMonthsNoOverflow($i));
            $staff = $this->rentalsStaff($b->company, $role);
            $done = $close->close($staff, Carbon::now()->subMonthNoOverflow()->format('Y-m'));
            $this->assertSame((int) $staff->id, (int) $done->closed_by_id);
        }
    }

    public function test_only_the_owner_reopens_only_the_latest_with_a_reason_and_it_is_written_down(): void
    {
        $b = $this->octoberClosed();
        Carbon::setTestNow('2026-12-02 09:00:00');
        $close = app(MonthClose::class);
        $close->close($b->owner, '2026-11');
        foreach (['rentals_manager', 'rentals_accountant'] as $role) {
            $this->refused('forbidden', fn () => $close->reopen($this->rentalsStaff($b->company, $role), '2026-11', 'I want to'));
        }
        $this->refused('reason_required', fn () => $close->reopen($b->owner, '2026-11', '   '));
        $this->refused('reopen_latest_only', fn () => $close->reopen($b->owner, '2026-10', 'Older first'));

        $row = $close->reopen($b->owner, '2026-11', 'A receipt was missed');
        $this->assertSame('reopened', $row->status);
        $this->assertSame('A receipt was missed', $row->reopen_reason);
        $this->assertSame((int) $b->owner->id, (int) $row->reopened_by_id);
        $this->assertSame('2026-10-31', MonthClose::closedThrough($b->cid));
        $this->assertSame(1, RentalActivity::where('company_id', $b->cid)->where('action', 'month.reopened')->count());

        // November takes a back-dated receipt again; October still does not.
        $b->pay->receive($b->owner, $b->lease, ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-11-20']);
        $this->refused('period_closed', fn () => $b->pay->receive($b->owner, $b->lease, ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-20']));

        // One at a time: then October, and with nothing closed before it the books are open.
        $close->reopen($b->owner, '2026-10', 'Older too');
        $this->assertNull(MonthClose::closedThrough($b->cid));
        $this->refused('reopen_latest_only', fn () => $close->reopen($b->owner, '2026-09', 'None closed'));
        $b->pay->receive($b->owner, $b->lease, ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-20']);
        $this->assertBooksAgree($b->cid);
    }

    public function test_with_everything_reopened_the_first_close_cannot_jump_past_a_reopened_month(): void
    {
        $b = $this->octoberClosed();
        Carbon::setTestNow('2026-12-02 09:00:00');
        $close = app(MonthClose::class);
        $close->reopen($b->owner, '2026-10', 'A fix');
        $this->assertNull(MonthClose::closedThrough($b->cid));
        $this->assertSame('2026-10', MonthClose::nextMonth($b->cid));
        $b->pay->receive($b->owner, $b->lease->fresh(), ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-20']);
        $this->refused('month_out_of_order', fn () => $close->close($b->owner, '2026-11'));
        $close->close($b->owner, '2026-09'); // earlier is allowed: it is a first close
        $this->assertSame('2026-10', MonthClose::nextMonth($b->cid));
        $close->close($b->owner, '2026-10');
        $close->close($b->owner, '2026-11');
        $this->assertBooksAgree($b->cid);
    }

    public function test_a_close_in_a_second_tab_is_refused_with_one_set_of_statements(): void
    {
        $b = $this->octoberClosed();
        $count = DB::table('rental_statements')->where('company_id', $b->cid)->count();
        $this->assertGreaterThan(0, $count);
        $e = $this->refused('month_already_closed', fn () => app(MonthClose::class)->close($b->owner, '2026-10'));
        $this->assertSame('October 2026 is already closed. Nothing changed.', $e->getMessage());
        $this->assertSame($count, DB::table('rental_statements')->where('company_id', $b->cid)->count());
    }

    public function test_nightly_billing_late_fees_and_credit_run_over_a_closed_month(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['late_fee_type' => 'percent', 'late_fee_bp' => 500]);
        $cid = (int) $company->id;
        [, $units] = $this->building($owner, 2, 300000);
        $paid = $this->tenantIn($owner, $units[0], '2026-10-01');
        $late = $this->tenantIn($owner, $units[1], '2026-10-01');
        app(Payments::class)->receive($owner, $paid, ['amount_minor' => 900000, 'method' => 'cash', 'paid_on' => '2026-10-01']);
        Carbon::setTestNow('2026-11-02 09:00:00');
        app(MonthClose::class)->close($owner, '2026-10');
        $before = (int) DB::table('rental_entries')->where('company_id', $cid)->where('occurred_on', '<=', '2026-10-31')->count();

        $r = app(RentalDaily::class)->run($cid);
        $this->assertSame(2, $r['bills']);
        $this->assertGreaterThanOrEqual(1, $r['late_fees']);
        $this->assertSame($before, (int) DB::table('rental_entries')->where('company_id', $cid)->where('occurred_on', '<=', '2026-10-31')->count(), 'nothing landed in October');
        $this->assertSame('2026-11-02', DB::table('rental_entries')->where('company_id', $cid)->where('type', 'credit_applied')->max('occurred_on'), 'the credit paid November\'s bill today');
        $this->assertSame(0, app(RentalDaily::class)->run($cid)['bills'], 'twice is once');

        // A date asked for by hand in a closed month is refused before a bill is raised.
        $billed = DB::table('rental_charges')->where('company_id', $cid)->count();
        $this->refused('period_closed', fn () => app(RentalDaily::class)->run($cid, Carbon::parse('2026-10-15')));
        $this->assertSame($billed, DB::table('rental_charges')->where('company_id', $cid)->count());
        $this->assertBooksAgree($cid);
        unset($late);
    }

    public function test_the_phone_is_refused_in_words_it_can_act_on_and_a_resend_stays_one_payment(): void
    {
        $b = $this->octoberClosed();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $h = $this->auth($b->owner->createToken('t')->plainTextToken);
        $body = ['lease_id' => $b->lease->id, 'amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-30', 'uuid' => (string) Str::uuid()];
        $before = $this->counts($b->cid);
        $res = $this->postJson('/api/v1/rentals/payments', $body, $h)->assertStatus(422);
        $this->assertSame('period_closed', $res->json('errors.code'));
        $this->assertSame('2026-11-03', $res->json('errors.use_date'));
        $this->assertSame('For October', $res->json('errors.mark'));
        $this->assertSame($before, $this->counts($b->cid));

        // The same phone sends it again as the screen would: dated today, marked. Its resend is the same payment.
        $again = ['paid_on' => '2026-11-03', 'notes' => 'For October'] + $body;
        $this->postJson('/api/v1/rentals/payments', $again, $h)->assertStatus(201);
        $this->postJson('/api/v1/rentals/payments', $again, $h)->assertStatus(200);
        $this->assertSame(1, RentalPayment::where('company_id', $b->cid)->where('uuid', $body['uuid'])->count());

        // A payment the phone sent before the close, resent after it, is still the first payment.
        $first = RentalPayment::where('company_id', $b->cid)->orderBy('id')->firstOrFail();
        $this->postJson('/api/v1/rentals/payments', ['lease_id' => $first->lease_id, 'amount_minor' => (int) $first->amount_minor, 'method' => $first->method, 'paid_on' => '2026-10-02', 'uuid' => (string) $first->uuid], $h)->assertStatus(200);

        foreach ([['/api/v1/rentals/payouts', ['landlord_id' => $b->landlord->id, 'amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-30']],
            ['/api/v1/rentals/expenses', ['amount_minor' => 1000, 'method' => 'cash', 'category' => 'Repairs', 'paid_on' => '2026-10-30', 'charged_to' => 'landlord', 'property_id' => $b->property->id]]] as [$url, $json]) {
            $r = $this->postJson($url, $json, $h);
            $r->assertStatus(422);
            $this->assertSame('period_closed', $r->json('errors.code'), $url);
        }
        $this->assertBooksAgree($b->cid);
    }

    public function test_an_import_into_a_closed_month_says_so_at_the_preview_and_writes_nothing(): void
    {
        $b = $this->octoberClosed();
        $before = DB::table('rental_landlords')->where('company_id', $b->cid)->count();
        $sheets = ['landlords' => [['name' => 'New Landlord', 'phone' => '0772123456', 'opening_balance' => '10000']], 'opening_cash' => [['method' => 'bank', 'amount' => '10000']]];
        $dry = app(RentalImport::class)->dryRun($b->cid, $b->owner, $sheets, '2026-10-15');
        $this->assertStringContainsString('closed', collect($dry['errors'])->firstWhere('sheet', 'as_of')['message']);
        $this->refused('import_invalid', fn () => app(RentalImport::class)->run($b->cid, $b->owner, $sheets, '2026-10-15'));
        $this->assertSame($before, DB::table('rental_landlords')->where('company_id', $b->cid)->count());
        $done = app(RentalImport::class)->run($b->cid, $b->owner, $sheets, '2026-11-03');
        $this->assertSame('2026-11-03', DB::table('rental_entries')->where('company_id', $b->cid)->where('type', 'opening_cash')->value('occurred_on'));
        $this->assertNotEmpty($done);
        $this->assertBooksAgree($b->cid);
    }

    public function test_a_tenants_report_confirmed_after_its_month_closed_is_a_late_receipt_for_that_month(): void
    {
        $b = $this->octoberClosed();
        app(RentalDaily::class)->run($b->cid);
        $report = RentalPaidReport::create(['company_id' => $b->cid, 'uuid' => (string) Str::uuid(), 'lease_id' => $b->lease->id, 'tenant_id' => $b->lease->tenant_id, 'unit_id' => $b->lease->unit_id,
            'property_id' => $b->lease->property_id, 'amount_minor' => 70000, 'method' => 'mobile_money', 'paid_on' => '2026-10-30', 'status' => 'pending']);
        $p = app(PaidReports::class)->confirm($b->owner, $report);
        $this->assertSame('2026-11-03', $p->paid_on->toDateString());
        $this->assertStringStartsWith('For October.', (string) $p->notes);
        $line = collect(LandlordAccounts::statement($b->landlord, '2026-11-01', '2026-11-30')['lines'])->first(fn ($l) => str_contains($l['description'], 'For October'));
        $this->assertNotNull($line, 'November\'s statement says it is for October');
        $this->assertBooksAgree($b->cid);
    }

    public function test_the_demos_hourly_upkeep_survives_a_visitor_closing_a_month_while_it_was_behind(): void
    {
        config(['rentals.demo.enabled' => true, 'edition.name' => 'saas']);
        $disk = sys_get_temp_dir().'/rentals-demo-close-'.uniqid();
        Storage::set('public', Storage::createLocalDriver(['root' => $disk]));
        Carbon::setTestNow('2026-11-03 10:00:00');
        $demo = new RentalsDemo;
        try {
            $demo->rebuild(null, RentalsDemo::SOLO);
            $company = RentalsDemo::company(RentalsDemo::SOLO);
            $cid = (int) $company->id;
            $owner = RentalsDemo::login('owner', RentalsDemo::SOLO);
            // The upkeep has not run since 28 October; a visitor closes October on 3 November.
            $state = (array) $company->onboarding_state;
            $state[RentalsDemo::MARK]['snapshot']['through'] = '2026-10-28';
            $company->onboarding_state = $state;
            $company->saveQuietly();
            $this->actingAs($owner);
            $close = app(MonthClose::class)->close($owner, '2026-10');
            $this->assertSame('closed', $close->status);
            $octoberRows = DB::table('rental_entries')->where('company_id', $cid)->where('occurred_on', '<=', '2026-10-31')->count();

            $state = (array) $company->fresh()->onboarding_state;
            $done = $demo->heal($company->fresh(), (array) $state[RentalsDemo::MARK], RentalsDemo::SOLO);
            $this->assertArrayHasKey('repairs', $done);
            $this->assertSame($octoberRows, DB::table('rental_entries')->where('company_id', $cid)->where('occurred_on', '<=', '2026-10-31')->count());
            $this->assertSame('2026-11-03', ((array) $company->fresh()->onboarding_state)[RentalsDemo::MARK]['snapshot']['through']);
            $this->assertSame('2026-10-31', MonthClose::closedThrough($cid), 'the heal leaves the visitor\'s close alone, or its statements would not stand');
            $this->assertSame([], (new RentalCheck)->run($cid));

            // The next month's hour after hour: nothing fails, and a rebuild takes the closes with the old account.
            Carbon::setTestNow('2026-12-01 01:00:00');
            $state = (array) $company->fresh()->onboarding_state;
            $demo->heal($company->fresh(), (array) $state[RentalsDemo::MARK], RentalsDemo::SOLO);
            $this->assertSame([], (new RentalCheck)->run($cid));
            $demo->rebuild($company->fresh(), RentalsDemo::SOLO);
            $this->assertSame(0, DB::table('rental_closes')->where('company_id', $cid)->count());
            $this->assertSame(0, DB::table('rental_statements')->where('company_id', $cid)->count());
        } finally {
            File::deleteDirectory($disk);
        }
    }

    public function test_three_hundred_random_operations_with_random_closes_keep_the_books_true(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['late_fee_type' => 'percent', 'late_fee_bp' => 500]);
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 4, 0);
        $leases = [];
        foreach ($units as $i => $u) {
            $leases[] = $this->tenantIn($owner, $u, '2026-01-0'.($i + 1), ['rent_minor' => 200000 + 50000 * $i, 'cycle_months' => [1, 1, 3, 1][$i], 'deposit_minor' => 100000 * $i]);
        }
        mt_srand(29);
        $pay = app(Payments::class);
        $close = app(MonthClose::class);
        $day = Carbon::parse('2026-01-01');
        $closes = 0;
        $reopens = 0;
        for ($k = 0; $k < 300; $k++) {
            $lease = $leases[mt_rand(0, 3)]->fresh();
            $op = mt_rand(0, 11);
            $back = $day->copy()->subDays(mt_rand(0, 45))->toDateString();
            try {
                if ($op <= 4) {
                    $pay->receive($owner, $lease, ['amount_minor' => mt_rand(1, 40) * 10000, 'method' => ['cash', 'mobile_money', 'bank'][mt_rand(0, 2)], 'paid_on' => $back]);
                } elseif ($op === 5 && ($p = RentalPayment::where('lease_id', $lease->id)->whereNull('reversed_at')->whereIn('method', ['cash', 'mobile_money', 'bank'])->inRandomOrder()->first())) {
                    $pay->reverse($owner, $p, 'random');
                } elseif ($op === 6 && LandlordAccounts::balance(RentalLandlord::withoutGlobalScopes()->findOrFail($property->landlord_id)) > 1000) {
                    app(Payouts::class)->record($owner, RentalLandlord::withoutGlobalScopes()->findOrFail($property->landlord_id), ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => $back]);
                } elseif ($op === 7) {
                    app(Expenses::class)->record($owner, ['amount_minor' => 500, 'method' => 'cash', 'category' => 'Repairs', 'paid_on' => $back, 'charged_to' => 'landlord', 'property_id' => $property->id]);
                } elseif ($op === 8 && ($next = MonthClose::nextMonth($cid)) !== null) {
                    $close->close($owner, $next);
                    $closes++;
                } elseif ($op === 9 && mt_rand(0, 3) === 0 && ($latest = MonthClose::latest($cid)) !== null) {
                    $close->reopen($owner, $latest->month->format('Y-m'), 'random');
                    $reopens++;
                } else {
                    $day = $day->copy()->addDays(mt_rand(1, 9));
                    Carbon::setTestNow($day->format('Y-m-d').' 09:00:00');
                    app(RentalDaily::class)->run($cid);
                }
            } catch (BusinessRuleException $e) {
                $this->assertContains($e->errorCode(), ['payment_refunded', 'insufficient_cash', 'credit_short', 'date_in_future', 'period_closed', 'deposit_gone'], $e->getMessage());
            }
            if ($k % 40 === 39) {
                $this->assertBooksAgree($cid);
            }
        }
        $this->assertGreaterThan(2, $closes);
        $this->assertGreaterThan(0, $reopens);
        $t = RentalLedger::totals($cid);
        $this->assertSame($t['cash_minor'], $t['landlord_minor'] + $t['deposit_minor'] + $t['credit_minor'] + $t['income_minor'] - $t['expense_minor'] + $t['equity_minor']);
        $this->assertBooksAgree($cid);
    }
}

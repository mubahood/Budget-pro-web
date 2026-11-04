<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalExpense;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalMeterReading;
use App\Models\RentalPayment;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Directory\Directory;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Directory\Tenants;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Jobs\RentalDaily;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Messages\RentalMessages;
use App\Services\Rentals\Meters;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Payments\Splits;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * What an agency does between the rent days (docs/estate-ledger/04-architecture.md §5.5–5.10, §13, §15, §16, §18): meters to
 * bills, repairs with a cost, expenses and reversals, moving cash, late fees, commission to the last unit, a deposit
 * from held to refund, the nightly run, messages with nothing sent, plan limits, personal IDs. Every money test ends
 * with the books check.
 */
class RentalsOperationsTest extends ApiTestCase
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

    private function agency(array $settings = [], int $units = 2, int $rent = 300000, array $property = []): array
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', $settings);
        [$p, $list] = $this->building($owner, $units, $rent, $property);

        return [$company, $owner, $p, $list, (int) $company->id];
    }

    private function pay(User $by, RentalLease $lease, int $amount, string $method = 'cash', array $over = []): RentalPayment
    {
        return app(Payments::class)->receive($by, $lease->fresh(), $over + ['amount_minor' => $amount, 'method' => $method]);
    }

    // ── Meters ───────────────────────────────────────────────────────────

    public function test_meter_readings_become_utility_bills(): void
    {
        [, $owner, $p, $units, $cid] = $this->agency([], 2, 300000, ['water_rate_minor' => 2500, 'power_rate_minor' => 800]);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $m = app(Meters::class);
        $read = fn (array $over) => $m->record($owner, $over + ['unit_id' => $units[0]->id, 'utility' => 'water']);

        // The first reading only sets where the meter stands.
        $first = $read(['reading' => '1000.5', 'read_on' => '2026-03-01']);
        $this->assertSame(['1000.5', '1000.5', '0', 0, null], [(string) $first->reading, (string) $first->previous, (string) $first->used, (int) $first->amount_minor, $first->charge_id]);

        // Used × rate, half up to the shilling: 12.345 × 2,500 = 30,862.5 → 30,863. A bill to the tenant living there, for the landlord, no commission.
        Carbon::setTestNow('2026-03-31 09:00:00');
        $second = $read(['reading' => '1,012.845', 'read_on' => '2026-03-31']);
        $this->assertSame(['12.345', 30863, 2500], [(string) $second->used, (int) $second->amount_minor, (int) $second->rate_minor]);
        $bill = RentalCharge::findOrFail($second->charge_id);
        $this->assertSame(['utility', 30863, 'landlord', 'none', (int) $lease->id, "meter:{$second->id}"], [$bill->kind, (int) $bill->amount_minor, $bill->goes_to, $bill->commission_type, (int) $bill->lease_id, $bill->dedupe_key]);
        $this->assertStringContainsString('12.345 units', $bill->description);
        // Each meter its own count: power has its own first reading and its own rate.
        $this->assertNull($read(['reading' => '5', 'utility' => 'power', 'read_on' => '2026-03-31'])->charge_id);

        // Zero used: the reading stands, no bill of nothing.
        $zero = $read(['reading' => '1012.845', 'read_on' => '2026-03-31']);
        $this->assertSame([0, null], [(int) $zero->amount_minor, $zero->charge_id]);

        // Lower than the last: refused, unless the meter was replaced.
        $this->refused('reading_lower', fn () => $read(['reading' => '10', 'read_on' => '2026-03-31']));
        $this->refused('reading_invalid', fn () => $read(['reading' => '-5']));
        $this->refused('reading_invalid', fn () => $read(['reading' => 'twelve']));
        $this->refused('reading_invalid', fn () => $read(['reading' => '1.2345']));
        $this->refused('utility_invalid', fn () => $read(['reading' => '5', 'utility' => 'gas']));
        $this->refused('date_in_future', fn () => $read(['reading' => '2000', 'read_on' => '2026-04-01']));
        // Rollover: a new meter counts from zero.
        $reset = $read(['reading' => '7', 'reset' => true, 'read_on' => '2026-03-31']);
        $this->assertSame(['0', '7', 17500], [(string) $reset->previous, (string) $reset->used, (int) $reset->amount_minor]);
        $this->assertTrue((bool) $reset->reset);

        // Wrong order: a reading dated before the last one is refused.
        $this->refused('reading_order', fn () => $read(['reading' => '9000', 'read_on' => '2026-03-15']));

        // The same reading sent twice (a phone's retry) is one bill.
        $uuid = (string) Str::uuid();
        $one = $read(['reading' => '10', 'read_on' => '2026-03-31', 'uuid' => $uuid]);
        $this->assertSame((int) $one->id, (int) $read(['reading' => '10', 'read_on' => '2026-03-31', 'uuid' => $uuid])->id);
        $this->assertSame(1, RentalCharge::where('dedupe_key', "meter:{$one->id}")->count());

        // A bill raised on credit is paid from it.
        $this->pay($owner, $lease, 1000000);
        $this->assertSame(0, (int) RentalCharge::where('lease_id', $lease->id)->where('status', 'open')->count());

        // An empty unit: the reading is the next tenant's start, no bill to anyone.
        $vacant1 = $m->record($owner, ['unit_id' => $units[1]->id, 'utility' => 'water', 'reading' => '50', 'read_on' => '2026-03-01']);
        $vacant2 = $m->record($owner, ['unit_id' => $units[1]->id, 'utility' => 'water', 'reading' => '60', 'read_on' => '2026-03-20']);
        $this->assertSame(['10', 0, null, null], [(string) $vacant2->used, (int) $vacant2->amount_minor, $vacant2->charge_id, $vacant2->lease_id]);
        $next = $this->tenantIn($owner, $units[1], '2026-03-25', ['deposit_minor' => 0]);
        $third = $m->record($owner, ['unit_id' => $units[1]->id, 'utility' => 'water', 'reading' => '61', 'read_on' => '2026-03-31']);
        $this->assertSame([2500, (int) $next->id], [(int) $third->amount_minor, (int) $third->lease_id], 'the new tenant pays from the reading they started on');

        // No rate set: no bill, the figures still kept.
        [, $owner2, , $u2, $cid2] = $this->agency([], 1);
        $this->tenantIn($owner2, $u2[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Meters::class)->record($owner2, ['unit_id' => $u2[0]->id, 'utility' => 'power', 'reading' => '1']);
        $free = app(Meters::class)->record($owner2, ['unit_id' => $u2[0]->id, 'utility' => 'power', 'reading' => '9']);
        $this->assertSame(['8', 0, null], [(string) $free->used, (int) $free->amount_minor, $free->charge_id]);
        $this->assertBooksAgree($cid);
        $this->assertBooksAgree($cid2);
    }

    public function test_a_final_reading_at_move_out_bills_the_tenant_leaving(): void
    {
        [, $owner, , $units, $cid] = $this->agency([], 1, 300000, ['water_rate_minor' => 1000]);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 100000]);
        $this->pay($owner, $lease, 400000);
        app(Meters::class)->record($owner, ['unit_id' => $units[0]->id, 'utility' => 'water', 'reading' => '100']);
        Carbon::setTestNow('2026-03-31 09:00:00');
        $out = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-31', 'readings' => [['utility' => 'water', 'reading' => '120']]]);
        $this->assertSame(20000, $out['settlement']['deposit_used_minor'], '20 units at 1,000 come out of the deposit');
        $this->assertSame(80000, $out['settlement']['deposit_kept_minor']);
        $this->assertBooksAgree($cid);
    }

    // ── Maintenance and expenses ─────────────────────────────────────────

    public function test_a_repair_with_a_cost_charges_the_landlord_or_the_agency(): void
    {
        [$company, $owner, $p, $units, $cid] = $this->agency();
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $this->pay($owner, $lease, 300000);
        $landlord = RentalLandlord::findOrFail($p->landlord_id);
        $this->assertSame(270000, LandlordAccounts::balance($landlord));
        $svc = app(Maintenance::class);

        $toLandlord = $svc->complete($owner, $svc->open($owner, $cid, ['unit_id' => $units[0]->id, 'title' => 'Tap', 'assignee_name' => 'Fundi']), ['cost_minor' => 40000, 'method' => 'cash', 'charged_to' => 'landlord']);
        $e = RentalExpense::findOrFail($toLandlord->expense_id);
        $this->assertSame(['landlord', (int) $landlord->id, 40000, 'Repairs', 'Fundi', (int) $toLandlord->id], [$e->charged_to, (int) $e->landlord_id, (int) $e->amount_minor, $e->category, $e->vendor, (int) $e->maintenance_id]);
        $this->assertSame([230000, 260000], [LandlordAccounts::balance($landlord), RentalLedger::cash($cid)['cash']]);
        $this->assertSame([40000, 'done'], [(int) $toLandlord->cost_minor, $toLandlord->status]);
        $st = LandlordAccounts::statement($landlord, '2026-03-01', '2026-03-31');
        $this->assertSame(40000, $st['totals']['expenses_minor'], 'on the landlord\'s statement');

        $toAgency = $svc->complete($owner, $svc->open($owner, $cid, ['property_id' => $p->id, 'title' => 'Gate']), ['cost_minor' => 10000, 'method' => 'cash', 'charged_to' => 'agency']);
        $this->assertSame([230000, 250000, 10000], [LandlordAccounts::balance($landlord), RentalLedger::cash($cid)['cash'], (int) RentalLedger::totals($cid)['expense_minor']]);
        $this->assertNull(RentalExpense::findOrFail($toAgency->expense_id)->landlord_id);

        // No cost, no money. Closing twice, or working on a closed ticket, is refused. Not enough in that account: refused, nothing changes.
        $free = $svc->complete($owner, $svc->open($owner, $cid, ['property_id' => $p->id, 'title' => 'Bulb']), ['resolution' => 'Done free']);
        $this->assertSame([null, null], [$free->cost_minor, $free->expense_id]);
        $this->refused('ticket_closed', fn () => $svc->complete($owner, $free, []));
        $this->refused('ticket_closed', fn () => $svc->update($owner, $free, ['title' => 'x']));
        $this->refused('ticket_closed', fn () => $svc->cancel($owner, $free, 'x'));
        $short = $svc->open($owner, $cid, ['property_id' => $p->id, 'title' => 'Roof']);
        $this->refused('insufficient_cash', fn () => $svc->complete($owner, $short, ['cost_minor' => 900000, 'method' => 'cash']));
        $this->assertSame('open', $short->fresh()->status);
        $this->refused('title_required', fn () => $svc->open($owner, $cid, ['property_id' => $p->id, 'title' => ' ']));
        $this->refused('not_found', fn () => $svc->open($owner, $cid, ['title' => 'x']));

        // A caretaker closes a repair; spending money is the office's (§7).
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        app(Properties::class)->assignStaff($owner, $p, [$caretaker->id]);
        $h = $this->auth($caretaker->createToken('t')->plainTextToken);
        $t = $svc->open($caretaker, $cid, ['property_id' => $p->id, 'title' => 'Mine']);
        $this->putJson("/api/v1/rentals/maintenance/{$t->id}", ['status' => 'done', 'cost_minor' => 1000], $h)->assertStatus(403)->assertJsonPath('errors.permission', 'rentals_expenses');
        $this->assertSame('open', $t->fresh()->status, 'nothing half done');
        $this->putJson("/api/v1/rentals/maintenance/{$t->id}", ['status' => 'done', 'resolution' => 'Fixed'], $h)->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertBooksAgree($cid);
    }

    public function test_expenses_and_their_reversals(): void
    {
        [$company, $owner, $p, $units, $cid] = $this->agency();
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $this->pay($owner, $lease, 300000);
        $landlord = RentalLandlord::findOrFail($p->landlord_id);
        $svc = app(Expenses::class);

        $e = $svc->record($owner, ['property_id' => $p->id, 'unit_id' => $units[0]->id, 'category' => 'Cleaning', 'amount_minor' => 20000, 'method' => 'cash', 'vendor' => 'Bosco']);
        $a = $svc->record($owner, ['category' => 'Agency office', 'amount_minor' => 5000, 'method' => 'cash', 'charged_to' => 'agency']);
        $this->assertSame(['landlord', 'agency', 'EX-0001', 'EX-0002'], [$e->charged_to, $a->charged_to, $e->number, $a->number]);
        $this->assertSame([275000, 250000], [RentalLedger::cash($cid)['cash'], LandlordAccounts::balance($landlord)]);

        $this->refused('property_required', fn () => $svc->record($owner, ['category' => 'Repairs', 'amount_minor' => 1, 'method' => 'cash', 'charged_to' => 'landlord']));
        $this->refused('category_required', fn () => $svc->record($owner, ['property_id' => $p->id, 'amount_minor' => 1, 'method' => 'cash']));
        $this->refused('method_invalid', fn () => $svc->record($owner, ['property_id' => $p->id, 'category' => 'x', 'amount_minor' => 1, 'method' => 'gold']));
        $this->refused('date_in_future', fn () => $svc->record($owner, ['property_id' => $p->id, 'category' => 'x', 'amount_minor' => 1, 'method' => 'cash', 'paid_on' => '2026-03-02']));
        $this->refused('not_found', fn () => $svc->record($owner, ['property_id' => $p->id, 'category' => 'x', 'amount_minor' => 1, 'method' => 'cash', 'unit_id' => 99999999]));
        $this->refused('not_found', fn () => $svc->record($owner, ['property_id' => $p->id, 'category' => 'x', 'amount_minor' => 1, 'method' => 'cash', 'maintenance_id' => 99999999]));
        // A shop's own category is free text, within reason.
        $this->assertSame('Bribes for the cat', $svc->record($owner, ['category' => 'Bribes for the cat', 'amount_minor' => 1, 'method' => 'cash', 'charged_to' => 'agency'])->category);

        // A reversal negates its row and leaves the original in place.
        $this->refused('reason_required', fn () => $svc->reverse($owner, $e, ' '));
        $back = $svc->reverse($owner, $e, 'Wrong property');
        $this->assertNotNull($back->reversed_at);
        $this->assertSame(270000, LandlordAccounts::balance($landlord));
        $rows = RentalEntry::where('expense_id', $e->id)->orderBy('id')->get();
        $this->assertSame([['expense_landlord', -20000], ['reversal', 20000]], $rows->map(fn ($r) => [$r->type, (int) $r->cash_minor])->all());
        $this->refused('already_reversed', fn () => $svc->reverse($owner, $e->fresh(), 'Again'));
        $svc->reverse($owner, $a, 'Oops');
        $this->assertSame(0, (int) RentalLedger::totals($cid)['expense_minor'] - 1, 'only the one shilling for the cat is left');

        // Who may: a caretaker neither records nor reverses.
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        $this->refused('forbidden', fn () => $svc->record($caretaker, ['category' => 'x', 'amount_minor' => 1, 'method' => 'cash', 'charged_to' => 'agency']));
        $this->refused('forbidden', fn () => $svc->reverse($caretaker, $e, 'x'));
        // A posted expense is never edited or deleted: reversed rows stay.
        $this->assertBooksAgree($cid);
    }

    public function test_cash_book_transfers_and_the_running_balance(): void
    {
        [, $owner, , $units, $cid] = $this->agency();
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $this->pay($owner, $lease, 300000, 'cash');
        $book = app(CashBook::class);
        $book->transfer($owner, 'cash', 'bank', 200000, null, 'Banking');
        $this->assertSame(['cash' => 100000, 'bank' => 200000, 'mobile_money' => 0, 'cheque' => 0, 'total' => 300000], CashBook::balances($cid));
        $this->refused('method_invalid', fn () => $book->transfer($owner, 'cash', 'cash', 1));
        $this->refused('method_invalid', fn () => $book->transfer($owner, 'cash', 'gold', 1));
        $this->refused('insufficient_cash', fn () => $book->transfer($owner, 'cash', 'bank', 100001));
        $this->refused('date_in_future', fn () => $book->transfer($owner, 'cash', 'bank', 1, '2026-04-01'));

        // Two legs, one group, each moving cash: no one's balance changes, the rows only balance together.
        $rows = RentalEntry::where('company_id', $cid)->where('type', 'transfer')->orderBy('id')->get();
        $this->assertSame([[-200000, 'cash'], [200000, 'bank']], $rows->map(fn ($r) => [(int) $r->cash_minor, $r->method])->all());
        $this->assertSame(1, $rows->pluck('group_id')->unique()->count());
        $this->assertSame(270000, (int) RentalLedger::totals($cid)['landlord_minor']);

        $st = CashBook::statement($cid, '2026-03-01', '2026-03-31');
        $this->assertSame([300000, 100000, 300000], array_column($st['rows'], 'balance_minor'));
        $this->assertSame([0, 300000, 500000, 200000], [$st['opening_minor'], $st['closing_minor'], $st['in_minor'], $st['out_minor']]);
        $this->assertSame(200000, CashBook::statement($cid, '2026-03-01', '2026-03-31', 'bank')['closing_minor']);
        $this->assertSame(100000, CashBook::statement($cid, '2026-03-01', '2026-03-31', 'cash')['closing_minor']);
        $this->assertBooksAgree($cid);
    }

    // ── Late fees ────────────────────────────────────────────────────────

    public function test_late_fees_flat_percent_no_compounding_idempotent_daily(): void
    {
        [, $owner, , $units, $cid] = $this->agency(['late_fee_type' => 'percent', 'late_fee_bp' => 1000, 'late_fee_to' => 'agency', 'grace_days' => 5]);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $this->pay($owner, $lease, 100000); // 200,000 left on March
        $daily = app(RentalDaily::class);

        // Inside grace (due + 5 = the 6th): nothing yet, on the 6th itself.
        $this->assertSame(0, $daily->run($cid, Carbon::parse('2026-03-06'))['late_fees']);
        $this->assertSame(0, RentalCharge::where('kind', 'late_fee')->where('lease_id', $lease->id)->count());
        // The 7th: one fee of 10 % of what is left, due today, to the agency, tied to the bill.
        Carbon::setTestNow('2026-03-07 09:00:00');
        $this->assertSame(1, $daily->run($cid)['late_fees']);
        $fee = RentalCharge::where('kind', 'late_fee')->where('lease_id', $lease->id)->firstOrFail();
        $march = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->firstOrFail();
        $this->assertSame([20000, 'agency', '2026-03-07', (int) $march->id, 'none'], [(int) $fee->amount_minor, $fee->goes_to, $fee->due_date->toDateString(), (int) $fee->parent_id, $fee->commission_type]);
        // Run again that day, and for days on end: one fee per rent bill, never a fee on a fee.
        foreach (['2026-03-07', '2026-03-08', '2026-03-20'] as $day) {
            Carbon::setTestNow($day.' 09:00:00');
            $this->assertSame(0, $daily->run($cid)['late_fees']);
        }
        $this->assertSame(1, RentalCharge::where('kind', 'late_fee')->count());
        // April's rent, unpaid, gets its own, in time; the late fee itself never does.
        Carbon::setTestNow('2026-04-10 09:00:00');
        $this->assertSame(1, $daily->run($cid)['late_fees']);
        $this->assertSame([20000, 30000], RentalCharge::where('kind', 'late_fee')->where('lease_id', $lease->id)->orderBy('id')->pluck('amount_minor')->map(fn ($x) => (int) $x)->all());

        // A fee is paid last on its due date, after rent; the agency keeps it, no commission (KR-10, KR-14).
        Carbon::setTestNow('2026-04-11 09:00:00');
        $p = $this->pay($owner, $lease, 200000 + 20000);
        $income = RentalLedger::totals($cid)['income_minor'];
        $this->assertSame(10000 + 20000 + 20000, (int) $income, '100k + 200k rent at 10 %, and the 20k fee');
        $this->assertSame('paid', $fee->fresh()->status);

        // Paid before the fee day, or a lease with fees off, or a cancelled lease: no fee.
        $paid = $this->tenantIn($owner, $units[1], '2026-04-01', ['deposit_minor' => 0, 'late_fee' => 'off']);
        Carbon::setTestNow('2026-04-30 09:00:00');
        $daily->run($cid);
        $this->assertSame(0, RentalCharge::where('lease_id', $paid->id)->where('kind', 'late_fee')->count());
        // Fees off for the account: never.
        [, $o2, , $u2, $c2] = $this->agency();
        $this->tenantIn($o2, $u2[0], '2026-03-01', ['deposit_minor' => 0]);
        $this->assertSame(0, app(RentalDaily::class)->run($c2, Carbon::parse('2026-03-30'))['late_fees']);
        $this->assertBooksAgree($cid);
        $this->assertBooksAgree($c2);
    }

    public function test_a_flat_late_fee_to_the_landlord_and_a_night_missed(): void
    {
        [, $owner, $p, $units, $cid] = $this->agency(['late_fee_type' => 'flat', 'late_fee_minor' => 15000, 'late_fee_to' => 'landlord']);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        // Nothing ran for two months: one catch-up raises every bill and one fee each, due on the night it ran.
        Carbon::setTestNow('2026-05-05 09:00:00');
        $r = app(RentalDaily::class)->run($cid);
        $this->assertSame(['bills' => 2, 'late_fees' => 2], $r, 'April and May rent; March was raised at move-in, and March and April are past grace');
        $this->assertSame(['2026-03-01', '2026-04-01', '2026-05-01'], RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->orderBy('due_date')->get()->map(fn ($c) => $c->due_date->toDateString())->all());
        $this->assertSame([15000, 15000], RentalCharge::where('kind', 'late_fee')->pluck('amount_minor')->map(fn ($x) => (int) $x)->all());
        $this->assertSame(['landlord', '2026-05-05'], [RentalCharge::where('kind', 'late_fee')->value('goes_to'), RentalCharge::where('kind', 'late_fee')->first()->due_date->toDateString()]);
        $this->assertSame(['bills' => 0, 'late_fees' => 0], app(RentalDaily::class)->run($cid));
        // Paid off: the landlord has the fee in full, no commission taken from it.
        $this->pay($owner, $lease, 930000);
        $this->assertSame(810000 + 30000, LandlordAccounts::balance(RentalLandlord::findOrFail($p->landlord_id)));
        $this->assertBooksAgree($cid);
    }

    // ── Commission ───────────────────────────────────────────────────────

    public function test_commission_splits_always_sum_exactly(): void
    {
        [, $owner, $p, $units, $cid] = $this->agency([], 2, 333333);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0, 'commission' => ['type' => 'percent', 'bp' => 725]]);
        // 7.25 % of 333,333 = 24,166.64 → 24,167 in full, whatever the part payments.
        $march = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->firstOrFail();
        foreach ([1, 2, 99, 1000, 7777, 100000, 224454] as $a) {
            $this->pay($owner, $lease, $a);
        }
        $allocs = RentalAllocation::where('charge_id', $march->id)->get();
        $this->assertSame(333333, (int) $allocs->sum('amount_minor'));
        $this->assertSame(24167, (int) $allocs->sum('commission_minor'));
        $this->assertSame(333333 - 24167, (int) $allocs->sum('landlord_minor'));
        foreach ($allocs as $x) {
            $this->assertSame((int) $x->amount_minor, $x->commission_minor + $x->landlord_minor + $x->agency_minor + $x->deposit_minor);
            $this->assertGreaterThanOrEqual(0, (int) $x->commission_minor);
        }

        // Flat, per month of rent: 33,333 on a quarter is 99,999, whatever the parts.
        $quarter = $this->tenantIn($owner, $units[1], '2026-03-01', ['deposit_minor' => 0, 'cycle_months' => 3, 'rent_minor' => 100000, 'commission' => ['type' => 'flat', 'minor' => 33333]]);
        $bill = RentalCharge::where('lease_id', $quarter->id)->where('kind', 'rent')->firstOrFail();
        $this->assertSame([300000, 99999], [(int) $bill->amount_minor, (int) $bill->commission_minor]);
        mt_srand(11);
        $left = 300000;
        while ($left > 0) {
            $a = min($left, mt_rand(1, 40000));
            $this->pay($owner, $quarter, $a);
            $left -= $a;
        }
        $this->assertSame(99999, (int) RentalAllocation::where('charge_id', $bill->id)->sum('commission_minor'));

        // A flat fee above the bill caps at the bill: the landlord is never owed less than nothing on it.
        $this->assertSame(1000, Splits::total(1000, 'flat', 0, 5000));
        $this->assertSame(['commission' => 1000, 'landlord' => 0, 'agency' => 0, 'deposit' => 0], Splits::split(['amount_minor' => 1000, 'goes_to' => 'landlord', 'commission_type' => 'flat', 'commission_bp' => 0, 'commission_minor' => 5000], 1000, 0, 'agency'));
        // Lease → property → landlord → settings.
        $this->assertSame(['percent', 1000], [$lease->fresh()->commission_type === 'percent' ? 'percent' : '', (int) RentalsModule::settings($cid)->commission_bp]);
        $this->assertBooksAgree($cid);
    }

    public function test_commission_is_exact_after_a_reversal_or_a_waiver_in_the_middle(): void
    {
        // A bill of 2 at 50 %: 1 of commission. Pay 1 (the commission rounds up to it), pay 1 (nothing more),
        // reverse the first, pay 1 again: the bill is paid and the commission is still 1, never 0 or 2.
        [, $owner, , $units, $cid] = $this->agency([], 1, 2);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0, 'commission' => ['type' => 'percent', 'bp' => 5000]]);
        $bill = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->firstOrFail();
        $first = $this->pay($owner, $lease, 1);
        $this->pay($owner, $lease, 1);
        app(Payments::class)->reverse($owner, $first, 'Bounced');
        $this->pay($owner, $lease, 1);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(1, (int) RentalAllocation::where('charge_id', $bill->id)->sum('commission_minor'));
        $this->assertSame(1, (int) RentalAllocation::where('charge_id', $bill->id)->sum('landlord_minor'));
        $this->assertBooksAgree($cid);

        // Any order of paying, reversing and re-paying on one bill ends on the exact total.
        [, $o2, , $u2, $c2] = $this->agency([], 1, 333333);
        $l2 = $this->tenantIn($o2, $u2[0], '2026-03-01', ['deposit_minor' => 0, 'commission' => ['type' => 'percent', 'bp' => 725]]);
        $b2 = RentalCharge::where('lease_id', $l2->id)->where('kind', 'rent')->firstOrFail();
        mt_srand(23);
        $payments = [];
        for ($k = 0; $k < 60; $k++) {
            $left = $b2->fresh()->left();
            if ($left > 0 && (mt_rand(0, 2) > 0 || $payments === [])) {
                $payments[] = $this->pay($o2, $l2, min($left, mt_rand(1, 50000)));
            } elseif ($payments !== []) {
                $i = array_rand($payments);
                app(Payments::class)->reverse($o2, $payments[$i]->fresh(), 'Random');
                array_splice($payments, $i, 1);
            }
        }
        $left = $b2->fresh()->left();
        if ($left > 0) {
            $this->pay($o2, $l2, $left);
        }
        $this->assertSame('paid', $b2->fresh()->status);
        $this->assertSame(24167, (int) RentalAllocation::where('charge_id', $b2->id)->sum('commission_minor'));
        $this->assertBooksAgree($c2);

        // A waiver carries no commission: forgiving half leaves commission on the money only, to the shilling.
        [, $o3, , $u3, $c3] = $this->agency([], 1, 100000);
        $l3 = $this->tenantIn($o3, $u3[0], '2026-03-01', ['deposit_minor' => 0]);
        $b3 = RentalCharge::where('lease_id', $l3->id)->where('kind', 'rent')->firstOrFail();
        $this->pay($o3, $l3, 25000);
        app(Payments::class)->waive($o3, $b3->fresh(), 50000, 'Landlord agreed');
        $this->pay($o3, $l3, 25000);
        $this->assertSame([5000, 45000], [(int) RentalAllocation::where('charge_id', $b3->id)->sum('commission_minor'), (int) RentalAllocation::where('charge_id', $b3->id)->sum('landlord_minor')]);
        $this->assertBooksAgree($c3);
    }

    // ── Deposits ─────────────────────────────────────────────────────────

    public function test_a_deposit_from_held_to_refund(): void
    {
        [, $owner, $p, $units, $cid] = $this->agency();
        $landlord = RentalLandlord::findOrFail($p->landlord_id);
        // Held by the agency: never the landlord's or the agency's money.
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 300000]);
        $this->pay($owner, $lease, 600000);
        $t = RentalLedger::totals($cid);
        $this->assertSame([300000, 270000, 30000], [$t['deposit_minor'], $t['landlord_minor'], $t['income_minor']]);
        $this->assertSame(300000, Deposits::held($lease->fresh()));

        // April unpaid, then they leave on the 10th: the deposit pays April (with its commission), the rest goes back.
        Carbon::setTestNow('2026-04-10 09:00:00');
        app(RentalDaily::class)->run($cid);
        $out = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-04-10', 'refund_deposit' => 'cash', 'damages' => [['description' => 'Window', 'amount_minor' => 50000, 'goes_to' => 'agency']]]);
        // Owed: April 300,000 + window 50,000, which is over the deposit, so it is all used and 50,000 stays owed.
        $this->assertSame(['forgiven_minor' => 0, 'deposit_used_minor' => 300000, 'deposit_refunded_minor' => 0, 'deposit_kept_minor' => 0, 'credit_refunded_minor' => 0, 'credit_kept_minor' => 0, 'still_owed_minor' => 50000], $out['settlement']);
        $this->assertSame(0, (int) RentalLedger::totals($cid)['deposit_minor']);
        $this->assertSame(270000 + 270000, LandlordAccounts::balance($landlord));

        // Held by the landlord: the money passes to them when paid; the agency pays it back for them.
        [, $o2, $p2, $u2, $c2] = $this->agency();
        $l2 = RentalLandlord::findOrFail($p2->landlord_id);
        $held = $this->tenantIn($o2, $u2[0], '2026-03-01', ['deposit_minor' => 200000, 'deposit_held_by' => 'landlord']);
        $this->pay($o2, $held, 200000);
        $this->assertSame([200000, 0], [LandlordAccounts::balance($l2), (int) RentalLedger::totals($c2)['deposit_minor']]);
        $this->assertSame(200000, Deposits::held($held->fresh()));
        $st = LandlordAccounts::statement($l2, '2026-03-01', '2026-03-31');
        $this->assertSame([0, 200000], [$st['totals']['collected_minor'], $st['totals']['deposits_minor']]);
        app(Leases::class)->moveOut($o2, $held->fresh(), ['date' => '2026-03-01', 'refund_deposit' => 'cash']);
        $this->assertSame([0, 0, 0], [LandlordAccounts::balance($l2), Deposits::held($held->fresh()), RentalLedger::cash($c2)['cash']]);
        $this->assertBooksAgree($cid);
        $this->assertBooksAgree($c2);
    }

    public function test_a_later_bill_that_was_forgiven_does_not_stop_a_move_out(): void
    {
        [, $owner, , $units, $cid] = $this->agency();
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        Carbon::setTestNow('2026-03-28 09:00:00');
        app(RentalDaily::class)->run($cid); // April is billed
        $april = RentalCharge::where('lease_id', $lease->id)->where('period_start', '2026-04-01')->where('kind', 'rent')->firstOrFail();
        $waiver = app(Payments::class)->waive($owner, $april, 100000, 'A goodwill discount');
        $this->assertSame(100000, (int) $april->fresh()->paid_minor);
        $this->pay($owner, $lease, 300000); // March
        // They leave before April begins: April's bill goes, and its discount with it.
        $this->assertSame(0, app(Leases::class)->moveOutPreview($lease->fresh(), ['date' => '2026-03-28'])['credit_kept_minor'], 'a discount is not money to give back');
        $out = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-28']);
        $this->assertSame('ended', $out['lease']->status);
        $this->assertSame(['void', 0], [$april->fresh()->status, (int) $april->fresh()->paid_minor]);
        $this->assertNotNull($waiver->fresh()->reversed_at);
        $this->assertSame(0, $out['settlement']['still_owed_minor']);
        $this->assertBooksAgree($cid);
    }

    // ── The nightly run ──────────────────────────────────────────────────

    public function test_rentals_daily_is_idempotent_and_catches_up(): void
    {
        [, $owner, , $units, $cid] = $this->agency(['bill_days_before' => 5]);
        $lease = $this->tenantIn($owner, $units[0], '2026-01-31', ['deposit_minor' => 0, 'items' => [['name' => 'Garbage', 'amount_minor' => 7000]]]);
        $charges = fn () => RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->orderBy('due_date')->get()->map(fn ($c) => $c->due_date->toDateString())->all();
        // 31 Jan → 28 Feb → 31 Mar: the anchor keeps its day, through a February (§5.1).
        $this->assertSame(['2026-01-31', '2026-02-28'], $charges(), 'the 28 Feb bill is due, the test clock being in March');
        $this->withoutMockingConsoleOutput();
        // Today + 5 days is 6 March: 31 March is not due; the run does nothing twice.
        $this->assertSame(0, Artisan::call('rentals:daily', ['--company' => $cid]));
        $this->assertStringContainsString('0 bill(s)', Artisan::output());
        // The server was off from March until July: the next night catches up, each period once, each item once.
        Carbon::setTestNow('2026-07-01 09:00:00');
        $this->assertSame(0, Artisan::call('rentals:daily', ['--company' => $cid]));
        $this->assertStringContainsString('8 bill(s)', Artisan::output(), 'four rents and four garbage fees');
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'], $charges());
        $this->assertSame(6, RentalCharge::where('lease_id', $lease->id)->where('kind', 'item')->count());
        $this->assertSame('2026-07-30', $lease->fresh()->billed_until->toDateString());
        $count = RentalCharge::where('company_id', $cid)->count();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(['bills' => 0, 'late_fees' => 0], app(RentalDaily::class)->run($cid));
        }
        $this->assertSame($count, RentalCharge::where('company_id', $cid)->count());
        // The command's --date runs as of that day; the check command passes; a tenant's advance covers what was billed.
        $this->assertSame(0, Artisan::call('rentals:daily', ['--company' => $cid, '--date' => '2026-07-27']));
        $this->assertSame('2026-08-30', $lease->fresh()->billed_until->toDateString());
        $this->pay($owner, $lease, 2500000);
        $this->assertSame(0, Artisan::call('rentals:check', ['--company' => $cid]));
        $this->assertStringContainsString('the books agree', Artisan::output());
        // Bills are never raised for an ended lease, nor past a fixed term or a notice.
        app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-07-01']);
        $this->assertSame(0, app(RentalDaily::class)->run($cid, Carbon::parse('2026-12-01'))['bills']);
        $fixed = $this->tenantIn($owner, $units[1], '2026-07-01', ['deposit_minor' => 0, 'end_date' => '2026-08-31']);
        app(RentalDaily::class)->run($cid, Carbon::parse('2026-12-01'));
        $this->assertSame(['2026-07-01', '2026-08-01'], RentalCharge::where('lease_id', $fixed->id)->where('kind', 'rent')->orderBy('due_date')->get()->map(fn ($c) => $c->due_date->toDateString())->all());
        $this->assertBooksAgree($cid);

        // One account failing does not stop the next.
        $this->assertArrayHasKey($cid, app(RentalDaily::class)->runAll(Carbon::parse('2026-12-01')));
        $this->assertContains($cid, RentalDaily::companyIds());
    }

    public function test_the_check_finds_books_that_do_not_agree(): void
    {
        [, $owner, , $units, $cid] = $this->agency();
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 100000]);
        $this->pay($owner, $lease, 500000);
        $this->assertBooksAgree($cid);
        $check = app(\App\Services\Rentals\Jobs\RentalCheck::class);
        $found = fn () => array_column($check->run($cid), 'check');
        $charge = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->firstOrFail();

        DB::table('rental_charges')->where('id', $charge->id)->update(['paid_minor' => 1]);
        $this->assertContains('charge_paid_cache', $found());
        DB::table('rental_charges')->where('id', $charge->id)->update(['paid_minor' => 300000]);
        DB::table('rental_charges')->where('id', $charge->id)->update(['status' => 'open']);
        $this->assertContains('charge_status', $found());
        DB::table('rental_charges')->where('id', $charge->id)->update(['status' => 'paid']);
        DB::table('rental_payments')->where('lease_id', $lease->id)->update(['unallocated_minor' => 5]);
        $this->assertContains('payment_credit_cache', $found());
        $this->assertContains('lease_credit', $found());
        DB::table('rental_payments')->where('lease_id', $lease->id)->update(['unallocated_minor' => 100000]);
        $row = RentalEntry::where('lease_id', $lease->id)->where('type', 'collected')->firstOrFail();
        DB::table('rental_entries')->where('id', $row->id)->update(['cash_minor' => (int) $row->cash_minor + 1]);
        $this->assertContains('unbalanced_rows', $found());
        $this->assertContains('unbalanced_postings', $found());
        DB::table('rental_entries')->where('id', $row->id)->update(['cash_minor' => (int) $row->cash_minor]);
        $this->assertBooksAgree($cid);
        // A posted row refuses to change or go.
        $this->refused('record_locked', fn () => RentalPayment::where('lease_id', $lease->id)->firstOrFail()->update(['amount_minor' => 1]));
        $this->refused('record_locked', fn () => RentalPayment::where('lease_id', $lease->id)->firstOrFail()->delete());
    }

    // ── Messages ─────────────────────────────────────────────────────────

    public function test_messages_are_texts_and_links_and_the_server_sends_nothing(): void
    {
        Http::fake();
        Mail::fake();
        [$company, $owner, , $units, $cid] = $this->agency(['payment_instructions' => "MoMo 0772 000000\nBank Stanbic"]);
        $before = DB::table('message_log')->count();
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'Asha', 'phone' => '0701234567']]);
        Carbon::setTestNow('2026-03-20 09:00:00');
        app(RentalDaily::class)->run($cid);
        $p = $this->pay($owner, $lease, 100000);

        $r = RentalMessages::reminder($lease->fresh());
        $this->assertSame('overdue', $r['kind']);
        $this->assertStringContainsString('Hello Asha, your rent for A1', $r['text']);
        $this->assertStringContainsString('You owe UGX 200,000', $r['text']);
        $this->assertStringContainsString('Pay to: MoMo 0772 000000 Bank Stanbic', $r['text'], 'one line, whatever was typed');
        $this->assertSame('https://wa.me/256701234567?text='.rawurlencode($r['text']), $r['whatsapp']);
        $this->assertSame('sms:+256701234567?body='.rawurlencode($r['text']), $r['sms']);

        $receipt = RentalMessages::forPayment($p);
        $this->assertStringContainsString('We received UGX 100,000', $receipt);
        $this->assertStringContainsString((string) $p->number, $receipt);

        // Every kind has a default; the account's own words replace it; an unknown placeholder comes out empty.
        foreach (RentalMessages::KINDS as $kind) {
            $this->assertNotSame('', RentalMessages::template($cid, $kind), $kind);
        }
        RentalsModule::settings($cid)->forceFill(['messages' => ['overdue' => 'Pay {tenant} {nonsense}now: {balance}']])->save();
        $this->assertSame('Pay Asha now: UGX 200,000', RentalMessages::forLease($lease->fresh(), 'overdue'));
        // A tenant with no phone: no link, no error.
        $this->assertNull(RentalMessages::whatsappLink(null, 'x'));
        $this->assertNull(RentalMessages::smsLink('', 'x'));
        // The whole month of work, and not one message went anywhere.
        $this->assertSame($before, DB::table('message_log')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        $this->assertFalse(app(Quotas::class)->featureOn($company, 'rentals_sms'), 'platform texts stay off on every plan');
        $this->assertBooksAgree($cid);
    }

    public function test_a_code_with_its_driver_off_sends_nothing_and_answers_the_same(): void
    {
        Http::fake();
        Mail::fake();
        [, $owner, , $units, $cid] = $this->agency();
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'Asha', 'email' => 'asha'.uniqid().'@example.com']]);
        $login = app(\App\Services\Rentals\Logins\RentalLogins::class)->enable($owner, 'tenant', (int) $lease->tenant_id);
        config(['messaging.mail.driver' => 'none']);
        $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => $login->email])->assertOk()->assertJsonPath('data.sent', true);
        $log = DB::table('message_log')->where('to', $login->email)->first();
        $this->assertSame('skipped', $log->status);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    // ── Plans ────────────────────────────────────────────────────────────

    private function onPlan(Company $company, string $slug): void
    {
        Subscription::where('company_id', $company->id)->update(['plan_id' => Plan::where('slug', $slug)->value('id')]);
    }

    public function test_the_units_quota_on_create_and_on_switching_back_on_and_money_is_never_refused(): void
    {
        [$company, $owner, $p, $units, $cid] = $this->agency([], 9);
        $this->onPlan($company, 'estate-ledger-free');
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $svc = app(Units::class);
        $company = Company::withoutGlobalScopes()->find($cid);
        $this->assertSame(['used' => 9, 'max' => 10, 'over' => false], app(Quotas::class)->usage($company)['units']);
        $tenth = $svc->create($owner, ['property_id' => $p->id, 'code' => 'B1', 'rent_minor' => 1000]);

        $e = $this->refused('plan_limit_reached', fn () => $svc->create($owner, ['property_id' => $p->id, 'code' => 'B2', 'rent_minor' => 1000]));
        $this->assertSame('Your plan allows 10 units. Upgrade to add more.', $e->getMessage());
        $this->assertSame('max_units', $e->meta()['limit']);
        $this->assertSame('estate-ledger-starter', $e->meta()['next_plan']);
        $h = $this->auth($owner->createToken('t')->plainTextToken);
        $this->postJson('/api/v1/rentals/units', ['property_id' => $p->id, 'code' => 'B2', 'rent_minor' => 1000], $h)->assertStatus(422)->assertJsonPath('errors.code', 'plan_limit_reached');
        // Adding many at once: all or none.
        $this->refused('plan_limit_reached', fn () => $svc->createMany($owner, (int) $p->id, 'C', 1, 2, ['rent_minor' => 1000]));
        $this->assertSame(10, app(Quotas::class)->used($company, 'units'));

        // A unit switched off frees its place; switching it back on takes it again, and is refused when the place has gone.
        $svc->update($owner, $tenth, ['status' => 'inactive']);
        $this->assertSame(9, app(Quotas::class)->used($company, 'units'));
        $replacement = $svc->create($owner, ['property_id' => $p->id, 'code' => 'B3', 'rent_minor' => 1000]);
        $this->refused('plan_limit_reached', fn () => $svc->update($owner, $tenth->fresh(), ['status' => 'available']));
        $this->assertSame('inactive', $tenth->fresh()->status);
        $svc->update($owner, $tenth->fresh(), ['notes' => 'Still off']);
        $svc->update($owner, $replacement, ['status' => 'maintenance']); // under repair still counts
        $this->assertSame(10, app(Quotas::class)->used($company, 'units'));

        // Rent, payouts, bills and move-ins into units already there are never refused for the plan.
        $this->pay($owner, $lease, 300000);
        $other = $this->tenantIn($owner, $units[1], '2026-03-01', ['deposit_minor' => 0]);
        app(Billing::class)->charge($owner, $other, ['description' => 'Key', 'amount_minor' => 1000]);
        $this->assertBooksAgree($cid);
    }

    public function test_quotas_count_on_import_and_a_downgraded_account_keeps_working(): void
    {
        [$company, $owner, $p, $units, $cid] = $this->agency([], 12);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        // Fell back to Free with 12 units (Lifecycle::freePlan): over the limit, nothing is taken away.
        $this->onPlan($company, 'estate-ledger-free');
        $company = Company::withoutGlobalScopes()->find($cid);
        $this->assertTrue(app(Quotas::class)->usage($company)['units']['over']);
        $h = $this->auth($owner->createToken('t')->plainTextToken);
        $this->getJson('/api/v1/rentals/units?per_page=100', $h)->assertOk()->assertJsonPath('meta.pagination.total', 12);
        $this->postJson('/api/v1/rentals/payments', ['lease_id' => $lease->id, 'amount_minor' => 300000, 'method' => 'cash'], $h)->assertStatus(201);
        $this->postJson('/api/v1/rentals/units', ['property_id' => $p->id, 'code' => 'Z', 'rent_minor' => 1000], $h)->assertStatus(422)->assertJsonPath('errors.code', 'plan_limit_reached');
        $imp = app(\App\Services\Rentals\Import\RentalImport::class);
        $name = (string) $p->name;
        $r = $imp->dryRun($cid, $owner, ['units' => "property_name,code,rent\n{$name},Z1,1000\n"], '2026-03-01');
        $this->assertStringContainsString('Your plan allows 10 units', $r['errors'][0]['message']);
        // Moving an imported tenancy into a unit already counted is fine.
        $this->assertSame([], $imp->dryRun($cid, $owner, ['tenancies' => "property_name,unit_code,tenant_name,start_date\n{$name},A2,X,2026-03-01\n"], '2026-03-01', ['allow_existing' => true])['errors']);

        // A plan with a team limit stops a new member the same way, in its own words.
        foreach (['estate-ledger-trial' => 300, 'estate-ledger-free' => 10, 'estate-ledger-starter' => 60, 'estate-ledger-growth' => 250, 'estate-ledger-agency' => 2000] as $slug => $n) {
            $this->assertSame($n, (int) Plan::where('slug', $slug)->first()->limits['max_units'], $slug);
        }
        $this->assertBooksAgree($cid);
    }

    // ── Personal IDs ─────────────────────────────────────────────────────

    public function test_personal_ids_are_encrypted_at_rest_and_never_in_logs_audit_or_exports(): void
    {
        $seen = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$seen) {
            $seen[] = $m->message.' '.json_encode($m->context);
        });
        [$company, $owner, $p, $units, $cid] = $this->agency();
        $h = $this->auth($owner->createToken('t')->plainTextToken);
        $tid = 'CF90SECRET789Z';
        $lid = 'CM70LANDLORD12';

        $t = $this->postJson('/api/v1/rentals/tenants', ['name' => 'Asha', 'phone' => '0701234567', 'id_type' => 'national_id', 'id_number' => $tid], $h)->assertStatus(201);
        $this->assertSame('CF••••••••789Z', $t->json('data.id_number_masked'));
        $landlord = $this->postJson('/api/v1/rentals/landlords', ['name' => 'Okello', 'id_number' => $lid], $h)->assertStatus(201);
        $tenant = RentalTenant::findOrFail($t->json('data.id'));
        $raw = DB::table('rental_tenants')->where('id', $tenant->id)->value('id_number');
        $this->assertStringNotContainsString($tid, (string) $raw);
        $this->assertSame($tid, Directory::reveal($raw), 'it can be read back with the key');
        $this->assertStringNotContainsString($lid, (string) DB::table('rental_landlords')->where('id', $landlord->json('data.id'))->value('id_number'));
        $this->assertArrayNotHasKey('id_number', $tenant->toArray(), 'hidden from any array or JSON of the model');

        // Editing it: encrypted again, cleared when cleared, the audit naming only the field.
        $this->putJson("/api/v1/rentals/tenants/{$tenant->id}", ['id_number' => 'NEW1234567'], $h)->assertOk()->assertJsonPath('data.id_number_masked', 'NE••••4567');
        $this->putJson("/api/v1/rentals/tenants/{$tenant->id}", ['id_number' => $tid], $h)->assertOk();

        // A full tenancy through move-in and reports.
        $lease = $this->postJson('/api/v1/rentals/leases', ['unit_id' => $units[0]->id, 'tenant_id' => $tenant->id, 'start_date' => '2026-03-01', 'first_payment' => ['amount_minor' => 300000, 'method' => 'cash']], $h)->assertStatus(201)->json('data');
        $this->assertStringNotContainsString($tid, (string) json_encode($lease));

        foreach ([...RentalReports::keys()] as $key) {
            foreach (['json', 'csv'] as $f) {
                $res = $this->getJson("/api/v1/rentals/reports/{$key}?format={$f}&lease_id={$lease['id']}&landlord_id={$p->landlord_id}&from=2026-03-01&to=2026-03-31", $h)->assertOk();
                $body = $f === 'json' ? (string) json_encode($res->json()) : (string) $this->get($res->json('data.url'))->getContent();
                $this->assertStringNotContainsString($tid, $body, $key);
                $this->assertStringNotContainsString('id_number', $body, $key);
            }
        }
        $activity = (string) json_encode($this->getJson('/api/v1/rentals/activity?per_page=100', $h)->assertOk()->json('data'));
        $this->assertStringNotContainsString($tid, $activity);
        $this->assertStringNotContainsString($lid, $activity);
        $this->assertStringNotContainsString($tid, (string) DB::table('rental_activities')->where('company_id', $cid)->pluck('meta')->implode(' '));
        $this->assertStringNotContainsString($tid, (string) DB::table('rental_activities')->where('company_id', $cid)->pluck('summary')->implode(' '));

        // A refused request carrying one: not in the logs either, nor in the answer.
        $res = $this->postJson('/api/v1/rentals/tenants', ['name' => str_repeat('x', 300), 'id_number' => $tid], $h)->assertStatus(422);
        $this->assertStringNotContainsString($tid, (string) $res->getContent());
        $this->assertStringNotContainsString($tid, implode(' ', $seen));
        $this->assertStringNotContainsString($lid, implode(' ', $seen));

        // A landlord's sign-in never sees it (RentalsPersonApiTest), nor does a caretaker: masked on their property.
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        app(Properties::class)->assignStaff($owner, $p, [$caretaker->id]);
        $this->assertSame('CF••••••••789Z', $this->getJson("/api/v1/rentals/tenants/{$tenant->id}", $this->auth($caretaker->createToken('t')->plainTextToken))->assertOk()->json('data.id_number_masked'));
        $this->assertBooksAgree($cid);
    }

    public function test_the_system_is_never_told_about_a_short_id_and_a_broken_one_shows_nothing(): void
    {
        $this->assertNull(Directory::masked(null));
        $this->assertSame('••••', Directory::masked(\App\Support\DataCrypt::encrypt('1234')));
        $this->assertSame('AB••••7890', Directory::masked(\App\Support\DataCrypt::encrypt('AB12347890')));
        $this->assertNull(Directory::masked('not encrypted at all'));
        $this->assertNull(Directory::reveal('not encrypted at all'));
    }
}

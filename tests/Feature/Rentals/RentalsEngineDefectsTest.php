<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalCharge;
use App\Models\RentalDocument;
use App\Models\RentalEntry;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Documents;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Payments\Receipt;
use App\Models\RentalLandlord;
use App\Services\Rentals\RentalsModule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * The engine defects the benchmark study named (budget-pro-new/docs/estate-ledger/01-benchmark-study.md §9.4): a waived
 * deposit counted as held; money stranded by a kept deposit or credit, or by reversing a deposit payment after its
 * refund; the cash guard with no way to enter opening cash, and how it stops a move-out; receipts and statements that
 * change when reprinted; documents with no writer. Every money test ends with rentals:check finding nothing.
 */
class RentalsEngineDefectsTest extends ApiTestCase
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

    private function token(User $user): array
    {
        return $this->auth($user->createToken('test')->plainTextToken);
    }

    /** A lease with a deposit bill, and another lease whose payment puts plenty of cash in the book. */
    private function agency(int $deposit = 100000): array
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 2, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => $deposit]);
        $other = $this->tenantIn($owner, $units[1], '2026-03-01', ['deposit_minor' => 0]);

        return [$company, $owner, $lease, $other];
    }

    // ── 1. A waived deposit is not held ──────────────────────────────────

    public function test_1_a_deposit_part_paid_refunds_only_what_was_paid(): void
    {
        [$company, $owner, $lease, $other] = $this->agency(100000);
        app(Payments::class)->receive($owner, $other, ['amount_minor' => 300000, 'method' => 'cash']); // cash from someone else, so the guard is no excuse
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 40000, 'method' => 'cash']); // 40 of the 100 deposit
        $this->assertSame(40000, Deposits::held($lease->fresh()));

        // Leaving on the day it started: no rent is owed, the unpaid 60 of the deposit is forgiven, the paid 40 goes back.
        $out = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-01', 'refund_deposit' => 'cash']);

        $this->assertSame(40000, $out['settlement']['deposit_refunded_minor'], 'only the 40 that was paid goes back');
        $this->assertSame(0, Deposits::held($lease->fresh()));
        // The defect as the study found it: every allocation on the deposit bill, waivers included, says 100 was held.
        $this->assertSame(100000, (int) \App\Models\RentalAllocation::whereIn('charge_id', RentalCharge::where('lease_id', $lease->id)->where('kind', 'deposit')->select('id'))->sum('amount_minor'));
        $this->assertSame(0, (int) RentalLedger::totals((int) $company->id, ['lease_id' => $lease->id])['deposit_minor']);
        $this->assertSame(['cash' => 300000, 'bank' => 0, 'mobile_money' => 0, 'cheque' => 0, 'total' => 300000], RentalLedger::cash((int) $company->id));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_1_a_forgiven_deposit_bill_holds_nothing_and_a_reversed_waiver_changes_nothing(): void
    {
        [$company, $owner, $lease] = $this->agency(100000);
        $bill = RentalCharge::where('lease_id', $lease->id)->where('kind', 'deposit')->firstOrFail();
        $waiver = app(Payments::class)->waive($owner, $bill, 100000, 'Landlord agreed no deposit');
        $this->assertSame(0, Deposits::held($lease->fresh()));
        app(Payments::class)->reverse($owner, $waiver, 'Mistake');
        $this->assertSame(0, Deposits::held($lease->fresh()));
        $this->assertBooksAgree((int) $company->id);
    }

    // ── 2. Money that could get stranded ─────────────────────────────────

    public function test_2_a_deposit_kept_at_move_out_is_refunded_later_through_the_api(): void
    {
        [$company, $owner, $lease] = $this->agency(100000);
        $cid = (int) $company->id;
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        Carbon::setTestNow('2026-03-02 09:00:00');
        $out = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-01']);
        $this->assertSame(100000, $out['settlement']['deposit_kept_minor']);

        $h = $this->token($owner);
        // Staff who may not settle a tenancy are refused, and a live lease's deposit stays held.
        $accountant = $this->rentalsStaff($company, 'rentals_accountant');
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-deposit", ['method' => 'cash'], $this->token($accountant))->assertStatus(403);
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-deposit", ['method' => 'nope'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'method_invalid');

        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-deposit", ['method' => 'cash', 'amount_minor' => 30000], $h)->assertOk()
            ->assertJsonPath('data.deposit_refunded_minor', 30000)->assertJsonPath('data.deposit_held_minor', 70000);
        $this->assertSame(70000, (int) RentalLedger::totals($cid)['deposit_minor']);
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-deposit", ['method' => 'cash'], $h)->assertOk()
            ->assertJsonPath('data.deposit_refunded_minor', 70000)->assertJsonPath('data.deposit_held_minor', 0);
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-deposit", ['method' => 'cash'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'nothing_held');

        $this->assertSame(0, (int) RentalLedger::totals($cid)['deposit_minor']);
        $this->assertSame(0, RentalLedger::cash($cid)['cash']);
        $this->assertBooksAgree($cid);
    }

    public function test_2_a_kept_deposit_first_settles_what_the_tenant_still_owes(): void
    {
        [$company, $owner, $lease] = $this->agency(100000);
        $cid = (int) $company->id;
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        Carbon::setTestNow('2026-03-02 09:00:00');
        app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-01']);
        // A damage found after they left.
        app(\App\Services\Rentals\Billing\Billing::class)->charge($owner, $lease->fresh(), ['description' => 'Broken door', 'amount_minor' => 25000]);

        $r = app(Deposits::class)->refundKept($owner, $lease->fresh(), null, 'cash');

        $this->assertSame(['deposit_used_minor' => 25000, 'deposit_refunded_minor' => 75000, 'deposit_held_minor' => 0, 'still_owed_minor' => 0], array_intersect_key($r, array_flip(['deposit_used_minor', 'deposit_refunded_minor', 'deposit_held_minor', 'still_owed_minor'])));
        $this->assertBooksAgree($cid);
    }

    public function test_2_a_live_tenants_deposit_stays_held_and_a_caretaker_cannot_refund(): void
    {
        [$company, $owner, $lease] = $this->agency(100000);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        $this->refused('lease_live', fn () => app(Deposits::class)->refundKept($owner, $lease->fresh(), null, 'cash'));
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        $this->refused('forbidden', fn () => app(Deposits::class)->refundKept($caretaker, $lease->fresh(), null, 'cash'));
        $this->assertSame(100000, Deposits::held($lease->fresh()));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_2_credit_kept_at_move_out_is_refunded_later(): void
    {
        [$company, $owner, $lease] = $this->agency(0);
        $cid = (int) $company->id;
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 500000, 'method' => 'cash']); // rent 300000 + 200000 ahead
        Carbon::setTestNow('2026-03-31 09:00:00');
        $out = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-31']);
        $this->assertSame(200000, $out['settlement']['credit_kept_minor']);

        $h = $this->token($owner);
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-credit", ['method' => 'bank'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_cash');
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-credit", ['method' => 'cash', 'amount_minor' => 50000], $h)->assertOk()
            ->assertJsonPath('data.credit_refunded_minor', 50000)->assertJsonPath('data.credit_minor', 150000);
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-credit", ['method' => 'cash'], $h)->assertOk()
            ->assertJsonPath('data.credit_refunded_minor', 150000)->assertJsonPath('data.credit_minor', 0);
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-credit", ['method' => 'cash'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'nothing_held');
        $this->assertSame(0, (int) RentalLedger::totals($cid)['credit_minor']);
        $this->assertBooksAgree($cid);
    }

    public function test_2_a_payment_of_a_deposit_cannot_be_reversed_once_the_deposit_was_refunded(): void
    {
        [$company, $owner, $lease] = $this->agency(100000);
        $cid = (int) $company->id;
        $p = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-01', 'refund_deposit' => 'cash']);

        $e = $this->refused('deposit_gone', fn () => app(Payments::class)->reverse($owner, $p->fresh(), 'Bounced'));
        $this->assertStringContainsString('refunded', $e->getMessage());

        $this->assertNull($p->fresh()->reversed_at, 'nothing moved');
        $this->assertSame(0, (int) RentalLedger::totals($cid)['deposit_minor'], 'the deposit account never goes below zero');
        $this->assertBooksAgree($cid);
    }

    public function test_2_a_deposit_payment_can_still_be_reversed_while_the_deposit_is_there(): void
    {
        [$company, $owner, $lease] = $this->agency(100000);
        $p = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        app(Payments::class)->reverse($owner, $p->fresh(), 'Bounced');
        $this->assertSame(0, Deposits::held($lease->fresh()));
        $this->assertBooksAgree((int) $company->id);
    }

    // ── 3. The cash guard ────────────────────────────────────────────────

    public function test_3_opening_cash_through_the_journal_lets_an_expense_come_before_income(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$property] = $this->building($owner, 1);
        $h = $this->token($owner);
        $expense = ['property_id' => $property->id, 'category' => 'Repairs', 'amount_minor' => 50000, 'method' => 'bank', 'charged_to' => 'agency'];

        // The guard's protection stays: nothing is recorded in the bank yet, and it says what to do.
        $refusal = $this->postJson('/api/v1/rentals/expenses', $expense, $h)->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_cash');
        $this->assertStringContainsString('opening', $refusal->json('message'));

        // Who may enter it.
        $accountant = $this->rentalsStaff($company, 'rentals_accountant');
        $this->postJson('/api/v1/rentals/cash/opening', ['method' => 'bank', 'amount_minor' => 200000], $this->token($accountant))->assertStatus(403);
        $this->postJson('/api/v1/rentals/cash/opening', ['method' => 'gold', 'amount_minor' => 200000], $h)->assertStatus(422)->assertJsonPath('errors.code', 'method_invalid');
        $this->postJson('/api/v1/rentals/cash/opening', ['method' => 'bank', 'amount_minor' => 200.5], $h)->assertStatus(422)->assertJsonPath('errors.code', 'amount_invalid');

        $this->postJson('/api/v1/rentals/cash/opening', ['method' => 'bank', 'amount_minor' => 200000, 'on' => '2026-02-28'], $h)->assertStatus(201)
            ->assertJsonPath('data.balances.bank', 200000);
        $row = RentalEntry::where('company_id', $cid)->where('type', 'opening_cash')->firstOrFail();
        $this->assertSame([200000, 200000, 'bank'], [(int) $row->cash_minor, (int) $row->equity_minor, $row->method], 'balanced against equity (ADR 0010)');

        // One opening balance per account: typed twice it is refused, corrected on purpose it replaces.
        $this->postJson('/api/v1/rentals/cash/opening', ['method' => 'bank', 'amount_minor' => 200000], $h)->assertStatus(422)->assertJsonPath('errors.code', 'opening_exists');
        $this->postJson('/api/v1/rentals/cash/opening', ['method' => 'bank', 'amount_minor' => 150000, 'replace' => true], $h)->assertStatus(201)->assertJsonPath('data.balances.bank', 150000);
        $this->assertSame(150000, (int) RentalLedger::totals($cid)['equity_minor']);

        $this->postJson('/api/v1/rentals/expenses', $expense, $h)->assertStatus(201);
        $this->assertSame(100000, RentalLedger::cash($cid)['bank']);

        // A real overdraft is still refused.
        $this->postJson('/api/v1/rentals/expenses', ['amount_minor' => 100001] + $expense, $h)->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_cash');
        $this->assertBooksAgree($cid);
    }

    public function test_3_a_move_out_whose_refund_the_cash_cannot_cover_is_refused_whole_in_clear_words(): void
    {
        [$company, $owner, $lease] = $this->agency(100000);
        $cid = (int) $company->id;
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 400000, 'method' => 'cash']);
        app(CashBook::class)->transfer($owner, 'cash', 'bank', 390000);
        $entries = RentalEntry::where('company_id', $cid)->count();
        $charges = RentalCharge::where('lease_id', $lease->id)->orderBy('id')->get(['id', 'status', 'paid_minor'])->toArray();
        $seq = (int) RentalsModule::settings($cid)->seq_receipt;

        Carbon::setTestNow('2026-03-02 09:00:00');
        $e = $this->refused('insufficient_cash', fn () => app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-02', 'unused_days' => true, 'refund_deposit' => 'cash',
            'damages' => [['description' => 'Key', 'amount_minor' => 5000]]]));
        $this->assertStringContainsString('not recorded', $e->getMessage());
        $this->assertStringContainsString('refund later', $e->getMessage());

        // Nothing at all happened: no half-done move-out.
        $this->assertSame('active', $lease->fresh()->status);
        $this->assertSame($entries, RentalEntry::where('company_id', $cid)->count());
        $this->assertSame($charges, RentalCharge::where('lease_id', $lease->id)->orderBy('id')->get(['id', 'status', 'paid_minor'])->toArray());
        $this->assertSame($seq, (int) RentalsModule::settings($cid)->seq_receipt);
        $this->assertSame(0, RentalPayment::where('lease_id', $lease->id)->where('method', 'waiver')->count());

        // The way through: move out and keep the money to refund later, from the account that has it.
        $out = app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-02']);
        $this->assertSame('ended', $out['lease']->status);
        app(Deposits::class)->refundKept($owner, $lease->fresh(), null, 'bank');
        $this->assertSame(0, Deposits::held($lease->fresh()));
        $this->assertBooksAgree($cid);
    }

    public function test_3_a_move_out_refunding_deposit_and_credit_from_one_account_needs_both_covered(): void
    {
        [$company, $owner, $lease] = $this->agency(100000);
        $cid = (int) $company->id;
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 500000, 'method' => 'cash']); // deposit 100000, rent 300000, credit 100000
        app(CashBook::class)->transfer($owner, 'cash', 'bank', 350000); // 150000 stays in cash: the deposit fits, deposit plus credit do not
        Carbon::setTestNow('2026-03-31 09:00:00');
        $this->refused('insufficient_cash', fn () => app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-31', 'refund_deposit' => 'cash', 'refund_credit' => 'cash']));
        $this->assertSame('active', $lease->fresh()->status);
        $this->assertSame(100000, Deposits::held($lease->fresh()));
        $this->assertBooksAgree($cid);
    }

    // ── 4. A reprint shows what the receipt showed when issued ───────────

    public function test_4_a_receipt_reprint_never_changes_except_to_say_it_was_reversed(): void
    {
        [$company, $owner, $lease] = $this->agency(0);
        $cid = (int) $company->id;
        $p = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 200000, 'method' => 'cash', 'reference' => 'MM123']);
        $issued = Receipt::text($p->fresh());
        $this->assertStringContainsString('100,000', $issued, 'still owed when issued');
        $this->assertStringContainsString('Paid up to', $issued);

        // Everything a receipt reads changes afterwards.
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 500000, 'method' => 'cash']);
        RentalTenant::where('id', $lease->tenant_id)->update(['name' => 'Someone Else Entirely']);
        RentalUnit::where('id', $lease->unit_id)->update(['code' => 'ZZ9']);
        DB::table('companies')->where('id', $cid)->update(['name' => 'Renamed Agency']);
        RentalsModule::settings($cid)->forceFill(['receipt_footer' => 'A new footer'])->save();
        Carbon::setTestNow('2026-03-28 09:00:00');
        app(\App\Services\Rentals\Billing\Billing::class)->raise($lease->fresh());

        $this->assertSame($issued, Receipt::text($p->fresh()), 'the same words, the same figures');
        $parts = Receipt::parts($p->fresh());
        $this->assertSame(100000, $parts['figures']['owed_minor']);

        // The API reads the same figures.
        $shape = $this->getJson("/api/v1/rentals/payments/{$p->id}", $this->token($owner))->assertOk();
        $this->assertSame(100000, $shape->json('data.owed_after_minor'));
        $this->assertSame($issued, $shape->json('data.receipt.text'));

        // The one thing a reprint adds: it was reversed.
        app(Payments::class)->reverse($owner, $p->fresh(), 'Bounced');
        $after = Receipt::text($p->fresh());
        $this->assertStringContainsString('REVERSED', $after);
        $this->assertStringContainsString(explode("\n", trim($issued))[0], $after);
        $this->assertStringNotContainsString('Someone Else', $after);
        $this->assertBooksAgree($cid);
    }

    public function test_4_a_snapshot_is_not_rewritten_and_every_kind_of_payment_has_one(): void
    {
        [$company, $owner, $lease] = $this->agency(50000);
        $bill = RentalCharge::where('lease_id', $lease->id)->where('kind', 'deposit')->firstOrFail();
        $waiver = app(Payments::class)->waive($owner, $bill, 50000, 'No deposit');
        $cash = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 300000, 'method' => 'cash']);
        foreach ([$waiver, $cash] as $p) {
            $this->assertIsArray($p->fresh()->receipt_snapshot);
        }
        // A phone's retry returns the first payment and its first receipt.
        $uuid = (string) Str::uuid();
        $first = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 1000, 'method' => 'cash', 'uuid' => $uuid]);
        $issued = Receipt::text($first->fresh());
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 2000, 'method' => 'cash']);
        $this->assertSame($issued, Receipt::text(app(Payments::class)->receive($owner, $lease, ['amount_minor' => 1000, 'method' => 'cash', 'uuid' => $uuid])));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_4_a_statement_for_a_closed_period_is_the_same_when_printed_again(): void
    {
        [$company, $owner, $lease, $other] = $this->agency(0);
        $cid = (int) $company->id;
        Carbon::setTestNow('2026-03-06 09:00:00');
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash', 'paid_on' => '2026-03-05']);
        Carbon::setTestNow('2026-03-31 18:00:00');
        $landlord = RentalLandlord::findOrFail($lease->landlord_id);
        $march = LandlordAccounts::statement($landlord, '2026-03-01', '2026-03-31');
        $this->assertSame(['units' => 2, 'occupied' => 2, 'vacant' => 0], $march['occupancy']);
        $this->assertSame(200000, array_sum(array_column(array_filter($march['arrears'], fn ($a) => $a['unit'] === 'A1'), 'owed_minor')));

        // April: the tenant pays the rest and leaves, the other tenant leaves, a name is corrected, a unit is added.
        Carbon::setTestNow('2026-04-20 09:00:00');
        app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 700000, 'method' => 'cash']);
        app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-04-20']);
        app(Payments::class)->receive($owner, $other->fresh(), ['amount_minor' => 300000, 'method' => 'cash', 'paid_on' => '2026-04-01']);
        app(\App\Services\Rentals\Billing\Billing::class)->raiseAll($cid);
        app(\App\Services\Rentals\Billing\Billing::class)->void($owner, RentalCharge::where('lease_id', $other->id)->where('kind', 'rent')->where('status', 'open')->orderByDesc('due_date')->firstOrFail(), 'Test');
        RentalTenant::where('id', $lease->tenant_id)->update(['name' => 'Renamed Tenant']);
        app(\App\Services\Rentals\Directory\Units::class)->create($owner, ['property_id' => $lease->property_id, 'code' => 'NEW', 'rent_minor' => 1000]);

        $again = LandlordAccounts::statement($landlord, '2026-03-01', '2026-03-31');
        $this->assertSame($march['occupancy'], $again['occupancy']);
        $figures = fn (array $st) => array_map(fn ($a) => array_diff_key($a, ['tenant' => 1]), $st['arrears']);
        $this->assertSame($figures($march), $figures($again));
        $this->assertSame($march['lines'], $again['lines']);
        $this->assertSame($march['totals'], $again['totals']);
        $this->assertSame($march['closing_minor'], $again['closing_minor']);
        $this->assertBooksAgree($cid);
    }

    // ── 5. Documents ─────────────────────────────────────────────────────

    private function storedFile(int $cid, string $purpose = 'rental_document'): StoredFile
    {
        $uuid = (string) Str::uuid();

        return StoredFile::withoutGlobalScopes()->create(['company_id' => $cid, 'uuid' => $uuid, 'purpose' => $purpose, 'path' => "files/{$cid}/{$uuid}.pdf", 'mime' => 'application/pdf', 'size_bytes' => 10]);
    }

    public function test_5_documents_are_added_and_removed_scoped_to_the_account_and_the_properties(): void
    {
        [$company, $owner, $lease] = $this->agency(0);
        $cid = (int) $company->id;
        $docs = app(Documents::class);
        $tenantId = (int) $lease->tenant_id;

        $agreement = $docs->add($owner, 'lease', (int) $lease->id, ['file_uuid' => $this->storedFile($cid)->uuid, 'name' => 'Signed agreement', 'kind' => 'agreement']);
        $idCopy = $docs->add($owner, 'tenant', $tenantId, ['file_uuid' => $this->storedFile($cid)->uuid, 'name' => 'National ID', 'kind' => 'id']);
        $this->assertSame(['lease', 'agreement'], [$agreement->owner_type, $agreement->kind]);
        $this->assertSame(1, $docs->list($owner, 'tenant', $tenantId)->count());

        // Refusals: a file of another purpose or another account, an owner of another account, no name.
        $this->refused('file_invalid', fn () => $docs->add($owner, 'lease', (int) $lease->id, ['file_uuid' => $this->storedFile($cid, 'rental_photo')->uuid, 'name' => 'X']));
        $this->refused('file_invalid', fn () => $docs->add($owner, 'lease', (int) $lease->id, ['file_uuid' => (string) Str::uuid(), 'name' => 'X']));
        [$other, $otherOwner] = $this->rentalsAccount();
        $this->refused('file_invalid', fn () => $docs->add($otherOwner, 'tenant', $this->tenantOf($otherOwner), ['file_uuid' => $this->storedFile($cid)->uuid, 'name' => 'X']));
        $this->refused('not_found', fn () => $docs->add($otherOwner, 'lease', (int) $lease->id, ['file_uuid' => $this->storedFile((int) $other->id)->uuid, 'name' => 'X']));
        $this->refused('name_required', fn () => $docs->add($owner, 'lease', (int) $lease->id, ['file_uuid' => $this->storedFile($cid)->uuid]));
        $this->refused('owner_invalid', fn () => $docs->add($owner, 'unit', 1, ['file_uuid' => $this->storedFile($cid)->uuid, 'name' => 'X']));
        // The same file added twice is one document.
        $this->assertSame((int) $agreement->id, (int) $docs->add($owner, 'lease', (int) $lease->id, ['file_uuid' => $agreement->file_uuid, 'name' => 'Signed agreement', 'kind' => 'agreement'])->id);

        // A caretaker: their properties only, read only, and never someone's ID copy.
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        $this->refused('not_found', fn () => $docs->list($caretaker, 'lease', (int) $lease->id));
        app(\App\Services\Rentals\Directory\Properties::class)->assignStaff($owner, \App\Models\RentalProperty::findOrFail($lease->property_id), [$caretaker->id]);
        $this->assertSame([(int) $agreement->id], $docs->list($caretaker, 'lease', (int) $lease->id)->pluck('id')->map(fn ($x) => (int) $x)->all());
        $this->assertSame([], $docs->list($caretaker, 'tenant', $tenantId)->all(), 'an ID copy is for those who manage tenants');
        $this->refused('forbidden', fn () => $docs->add($caretaker, 'lease', (int) $lease->id, ['file_uuid' => $this->storedFile($cid)->uuid, 'name' => 'X']));
        $this->refused('forbidden', fn () => $docs->remove($caretaker, $agreement));

        // Removing takes the row and the file; the audit trail says who did it, with no file contents.
        $this->refused('not_found', fn () => $docs->remove($otherOwner, $agreement));
        $docs->remove($owner, $agreement);
        $this->assertNull(RentalDocument::find($agreement->id));
        $this->assertNull(StoredFile::withoutGlobalScopes()->where('uuid', $agreement->file_uuid)->first());
        $this->assertSame(2, DB::table('rental_activities')->where('company_id', $cid)->where('action', 'like', 'document.%')->whereIn('action', ['document.removed', 'document.added'])->where('subject_id', $agreement->id)->count());

        // Over the API: the shape, a signed link, and another account's document is a 404.
        $h = $this->token($owner);
        $file = $this->storedFile($cid)->uuid;
        $doc = $this->postJson('/api/v1/rentals/documents', ['owner_type' => 'lease', 'owner_id' => $lease->id, 'file_uuid' => $file, 'name' => 'Inventory', 'kind' => 'other'], $h)->assertStatus(201);
        $this->assertSame(['id', 'owner_type', 'owner_id', 'kind', 'name', 'file_uuid', 'url', 'created_at'], array_keys($doc->json('data')));
        $this->getJson("/api/v1/rentals/documents?owner_type=lease&owner_id={$lease->id}", $h)->assertOk()->assertJsonPath('data.0.name', 'Inventory');
        $this->getJson("/api/v1/rentals/documents?owner_type=lease&owner_id={$lease->id}", $this->token($otherOwner))->assertStatus(404);
        $this->deleteJson('/api/v1/rentals/documents/'.$doc->json('data.id'), [], $this->token($otherOwner))->assertStatus(404);
        $this->deleteJson('/api/v1/rentals/documents/'.$doc->json('data.id'), [], $h)->assertOk();
    }

    private function tenantOf(User $owner): int
    {
        [, $units] = $this->building($owner, 1);

        return (int) $this->tenantIn($owner, $units[0], '2026-03-01')->tenant_id;
    }
}

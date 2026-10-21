<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoLoan;
use App\Models\SaccoLoanChange;
use App\Models\SaccoLoanDocument;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoLoanRepayment;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoLoanVote;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Loans\LoanLedger;
use App\Support\Sacco\Amount;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.4 (04 §3.4, ADR 0009 §7): the loan tables exist with their money as BIGINT minor units, the
 * loan's balances are caches only the posting service moves (through LoanLedger, under 1300/1320/1330/1340), the
 * history rows refuse edits and deletes, and another SACCO's loan can never be posted to.
 */
class LoanSchemaTest extends ApiTestCase
{
    use MakesSaccoLoans;

    private function loan(int $cid, int $memberId): SaccoLoan
    {
        $product = SaccoLoanProduct::where('company_id', $cid)->firstOrFail();

        return SaccoLoan::create(['company_id' => $cid, 'member_id' => $memberId, 'product_id' => $product->id, 'status' => 'active', 'applied_minor' => 500_000,
            'currency' => 'UGX', 'term' => 6, 'terms' => $product->terms(6)->toArray(), 'uuid' => (string) Str::uuid()]);
    }

    public function test_tables_and_money_columns(): void
    {
        foreach (['sacco_loans', 'sacco_loan_schedule_lines', 'sacco_loan_guarantors', 'sacco_loan_collateral', 'sacco_loan_votes', 'sacco_loan_documents',
            'sacco_loan_repayments', 'sacco_loan_changes'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            foreach (Schema::getColumnListing($table) as $column) {
                if (str_ends_with($column, '_minor')) {
                    $this->assertStringContainsString('bigint', strtolower(Schema::getColumnType($table, $column, true)), "{$table}.{$column}");
                }
            }
        }
    }

    public function test_the_loan_sub_ledger_reconciles_and_refuses_edits_and_other_saccos(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $member = $this->loanMember($company, $owner);
        $loan = $this->loan($cid, (int) $member->id);
        $acc = LoanLedger::accounts($cid);
        $ugx = fn (int $m) => Amount::of($m, 'UGX');
        $post = new PostingService();
        $post->post(JournalDraft::make($cid, 'loan_disbursement', 'test:disb:'.$loan->id)
            ->debit($acc['principal'], $ugx(500_000), 'loan', (int) $loan->id)->credit(ChartOfAccounts::id($cid, 'cash.office'), $ugx(500_000)));
        $post->post(JournalDraft::make($cid, 'interest_due', 'test:int:'.$loan->id)
            ->debit($acc['interest'], $ugx(10_000), 'loan', (int) $loan->id)->credit(ChartOfAccounts::id($cid, 'inc.interest'), $ugx(10_000)));
        $post->post(JournalDraft::make($cid, 'penalty', 'test:pen:'.$loan->id)
            ->debit($acc['penalty'], $ugx(1_500), 'loan', (int) $loan->id)->credit(ChartOfAccounts::id($cid, 'inc.penalty'), $ugx(1_500)));
        $loan->refresh();
        $this->assertSame([500_000, 10_000, 1_500, 0], [$loan->principal_outstanding_minor, $loan->interest_due_minor, $loan->penalty_due_minor, $loan->fees_due_minor]);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
        $this->assertSame(511_500, $loan->owedMinor());

        $this->assertSame('ledger_cache', $this->errorCode(fn () => $loan->fill(['principal_outstanding_minor' => 1])->save()));
        $this->assertSame('loan_history', $this->errorCode(fn () => $loan->fresh()->delete()));
        $this->assertSame('none', $this->errorCode(fn () => $loan->fresh()->fill(['classification' => 'watch'])->save()), 'workflow columns move');
        $this->assertSame('sub_ledger_required', $this->errorCode(fn () => $post->post(JournalDraft::make($cid, 'loan_disbursement', 'test:bad')
            ->debit($acc['principal'], $ugx(5))->credit(ChartOfAccounts::id($cid, 'cash.office'), $ugx(5)))), 'a line on 1300 must name its loan');

        [$other, $otherOwner] = $this->loanSacco();
        $otherAcc = LoanLedger::accounts((int) $other->id);
        $this->assertSame('sub_account_not_found', $this->errorCode(fn () => $post->post(JournalDraft::make((int) $other->id, 'loan_disbursement', 'test:x')
            ->debit($otherAcc['principal'], $ugx(5), 'loan', (int) $loan->id)->credit(ChartOfAccounts::id((int) $other->id, 'cash.office'), $ugx(5)))), 'another SACCO\'s loan');
        $this->assertSame(0, SaccoLoan::withoutGlobalScopes()->where('company_id', $other->id)->count());
        $this->actingAs($otherOwner);
        $this->assertNull(SaccoLoan::find($loan->id), 'CompanyScope hides it');
    }

    public function test_history_rows_are_final(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $loan = $this->loan($cid, (int) $this->loanMember($company, $owner)->id);
        $line = SaccoLoanScheduleLine::create(['company_id' => $cid, 'loan_id' => $loan->id, 'schedule_version' => 1, 'installment_no' => 1, 'due_on' => '2026-12-01',
            'opening_principal_minor' => 500_000, 'principal_due_minor' => 80_000, 'interest_due_minor' => 10_000]);
        $this->assertSame('none', $this->errorCode(fn () => $line->fill(['principal_paid_minor' => 1000, 'status' => 'partial'])->save()));
        $this->assertSame('schedule_fixed', $this->errorCode(fn () => $line->fill(['principal_due_minor' => 1])->save()));
        $vote = SaccoLoanVote::create(['company_id' => $cid, 'loan_id' => $loan->id, 'officer_id' => 1, 'vote' => 'approve']);
        $this->assertSame('vote_final', $this->errorCode(fn () => $vote->fill(['vote' => 'reject'])->save()));
        $doc = SaccoLoanDocument::create(['company_id' => $cid, 'loan_id' => $loan->id, 'type' => 'contract', 'path' => 'x.pdf', 'sha256' => str_repeat('a', 64)]);
        $this->assertSame('document_final', $this->errorCode(fn () => $doc->fill(['sha256' => str_repeat('b', 64)])->save()));
        $rep = SaccoLoanRepayment::create(['company_id' => $cid, 'loan_id' => $loan->id, 'uuid' => (string) Str::uuid(), 'kind' => 'repayment', 'channel' => 'cash',
            'amount_minor' => 1000, 'value_date' => '2026-12-01', 'schedule_version' => 1]);
        $this->assertSame('immutable_event', $this->errorCode(fn () => $rep->fill(['amount_minor' => 2000])->save()));
        $this->assertSame('none', $this->errorCode(fn () => $rep->fresh()->fill(['status' => 'reversed'])->save()));
        $change = SaccoLoanChange::create(['company_id' => $cid, 'loan_id' => $loan->id, 'kind' => 'reschedule', 'effective_on' => '2026-12-01']);
        $this->assertSame('loan_history', $this->errorCode(fn () => $change->fill(['reason' => 'x'])->save()));
        $this->assertSame('loan_history', $this->errorCode(fn () => $change->delete()));
    }
}

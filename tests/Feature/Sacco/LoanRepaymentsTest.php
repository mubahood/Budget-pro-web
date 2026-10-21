<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoJournalEntry;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanRepayment;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoTransaction;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\LoanPostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Loans\Repayments;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use App\Support\Sacco\Amount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.10 (04 §5.1, §9.1; AK-24, AK-27, AK-39; SI 108 reg. 12): a repayment is a receipt split penalty →
 * fees → interest → principal (suspense recognised on receipt), the overpayment goes to savings, settlement pays
 * interest to the day and no fee and closes the loan (interest stops), a phone's cash repayment posts once however
 * often it is pushed (held in 2150 when the loan is gone), a reversal reopens it, and Σ loans = 1300 throughout.
 */
class LoanRepaymentsTest extends ApiTestCase
{
    use MakesSaccoLoans;

    private function charge(SaccoLoan $loan, string $what, int $minor, string $key): void
    {
        $rules = new LoanPostingRules((int) $loan->company_id, 'office');
        $draft = $what === 'interest' ? $rules->interestDue($key, $loan, Amount::of($minor, 'UGX')) : $rules->penalty($key, $loan, Amount::of($minor, 'UGX'));
        (new PostingService())->post($draft);
    }

    private function verified(int $cid): void
    {
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_allocation_order_receipt_overpayment_and_settlement(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $member = $this->loanMember($company, $owner);
        $account = $this->loanSavings($company, $member, 1_000_000, $owner);
        $loan = $this->activeLoan($company, $member, 1_000_000, 12, ['channel' => 'cash']);
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->charge($loan, 'interest', 24_000, 'test:i1');
        $this->charge($loan, 'penalty', 1_500, 'test:p1');
        SaccoLoanScheduleLine::where('loan_id', $loan->id)->where('installment_no', 1)->update(['interest_charged_minor' => 24_000]);
        DB::table('sacco_loans')->where('id', $loan->id)->update(['interest_in_suspense_minor' => 3_000]);

        $r = (new Repayments())->record($company, (int) $loan->id, ['amount_minor' => 50_000, 'channel' => 'cash'], $teller);
        $this->assertSame([1_500, 24_000, 3_000, 21_500, 0], [$r->penalty_minor, $r->interest_minor, $r->interest_suspense_minor, $r->principal_minor, $r->overpayment_minor],
            'penalty, then interest owed, then interest in suspense, then principal');
        $loan->refresh();
        $this->assertSame([0, 0, 0, 978_500], [$loan->penalty_due_minor, $loan->interest_due_minor, $loan->interest_in_suspense_minor, $loan->principal_outstanding_minor]);
        $tx = SaccoTransaction::find($r->transaction_id);
        $this->assertSame(['loan_repayment', (int) $loan->id], [$tx->kind, (int) $tx->loan_id]);
        $this->assertMatchesRegularExpression('/^RCT-/', (string) $tx->receipt_no);
        $line = SaccoLoanScheduleLine::where('loan_id', $loan->id)->where('installment_no', 1)->first();
        $this->assertSame(['partial', 21_500, 24_000], [$line->status, $line->principal_paid_minor, $line->interest_paid_minor]);
        $this->assertNotNull(DB::table('message_log')->where('purpose', 'sacco_receipt_repayment')->where('company_id', $cid)->first());
        $this->verified($cid);

        $this->assertSame('insufficient_available', $this->errorCode(fn () => (new Repayments())->record($company, (int) $loan->id,
            ['amount_minor' => 2_000_000, 'channel' => 'internal', 'savings_account_id' => $account->id], $teller)));

        $quote = (new Repayments())->quote($cid, (int) $loan->id);
        $this->assertSame(0, $quote['early_settlement_fee_minor']);
        $this->assertSame(978_500, $quote['principal_minor']);
        $before = (int) SaccoSavingsAccount::find($account->id)->balance_minor;
        $g = $this->loanMember($company, $owner);
        $this->loanSavings($company, $g, 500_000, $owner);
        $done = (new Repayments())->record($company, (int) $loan->id, ['amount_minor' => $quote['total_minor'] + 7_000, 'channel' => 'cash'], $teller);
        $this->assertSame([$quote['interest_to_day_minor'], 7_000], [$done->interest_to_day_minor, $done->overpayment_minor], 'interest to the day, and the rest to savings');
        $this->assertSame($before + 7_000, (int) SaccoSavingsAccount::find($account->id)->balance_minor);
        $loan->refresh();
        $this->assertSame(['closed', 0], [$loan->status, $loan->owedMinor()]);
        $this->assertSame(12, SaccoLoanScheduleLine::where('loan_id', $loan->id)->where('status', 'paid')->count());
        $this->assertSame('loan_closed', $this->errorCode(fn () => (new Repayments())->record($company, (int) $loan->id, ['amount_minor' => 100, 'channel' => 'cash'], $teller)));
        $this->verified($cid);
    }

    public function test_idempotent_offline_sync_held_receipt_and_reversal(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $member = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member, 1_000_000, $owner);
        $loan = $this->activeLoan($company, $member, 600_000, 6, ['channel' => 'savings']);
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $uuid = (string) Str::uuid();
        $svc = new Repayments();
        $a = $svc->record($company, (int) $loan->id, ['uuid' => $uuid, 'amount_minor' => 100_000, 'channel' => 'cash'], $teller);
        $b = $svc->record($company, (int) $loan->id, ['uuid' => $uuid, 'amount_minor' => 100_000, 'channel' => 'cash'], $teller);
        $this->assertSame((int) $a->id, (int) $b->id);
        $this->assertSame(1, SaccoJournalEntry::where('company_id', $cid)->where('idempotency_key', 'txn:'.$uuid)->count());
        $this->assertSame('idempotency_conflict', $this->errorCode(fn () => $svc->record($company, (int) $loan->id, ['uuid' => $uuid, 'amount_minor' => 99_000, 'channel' => 'cash'], $teller)));

        // A phone's cash repayment, pushed three times: one posting.
        $op = (string) Str::uuid();
        $stx = app(SaccoTransactions::class);
        foreach (range(1, 3) as $i) {
            $res = $stx->applySynced($company, ['kind' => 'loan_repayment', 'loan_uuid' => $loan->uuid, 'member_id' => $member->id, 'amount_minor' => 50_000,
                'value_date' => now()->toDateString(), 'receipt_no' => 'D1-0007'], $teller, 'device-1', $op);
            $this->assertSame($i === 1 ? 'applied' : 'replayed', $res['status']);
        }
        $this->assertSame(450_000, $loan->fresh()->principal_outstanding_minor);
        $this->assertSame(2, SaccoLoanRepayment::where('loan_id', $loan->id)->count());

        $res = $stx->applySynced($company, ['kind' => 'loan_repayment', 'loan_id' => 999999999, 'member_id' => $member->id, 'amount_minor' => 5_000], $teller, 'device-1', (string) Str::uuid());
        $this->assertSame(['applied', 'held', 'held', 'unknown_account'], [$res['status'], $res['code'], $res['model']->status, $res['model']->hold_reason], 'cash taken offline is never refused');
        $this->assertSame(5_000, (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, 'suspense.receipts'))->value('balance_minor'));
        $withdrawal = $stx->applySynced($company, ['kind' => 'withdrawal', 'member_id' => $member->id, 'amount_minor' => 5_000], $teller, 'device-1', (string) Str::uuid());
        $this->assertSame('offline_not_allowed', $withdrawal['code']);

        // Reversing a repayment (a second officer approves) puts the money back on the loan.
        $approval = $stx->reverse($company, (int) $a->transaction_id, 'Counted twice', $teller);
        (new SaccoApprovals())->approve($company, (int) $approval->id, $owner);
        $this->assertSame('reversed', SaccoLoanRepayment::find($a->id)->status);
        $this->assertSame(550_000, $loan->fresh()->principal_outstanding_minor);
        $this->verified($cid);
    }

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        yield 'seed 11' => [11];
        yield 'seed 2026' => [2026];
    }

    /** @dataProvider seeds */
    public function test_property_random_repayments_keep_the_loan_sub_ledger_equal_to_its_controls(int $seed): void
    {
        mt_srand($seed);
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $loans = [];
        foreach (range(1, 3) as $i) {
            $m = $this->loanMember($company, $owner);
            $acct = $this->loanSavings($company, $m, 2_000_000, $owner);
            $loans[] = [$this->activeLoan($company, $m, mt_rand(2, 9) * 100_000, mt_rand(3, 12), ['channel' => mt_rand(0, 1) ? 'cash' : 'savings']), $acct];
        }
        for ($step = 0; $step < 40; $step++) {
            [$loan, $acct] = $loans[mt_rand(0, 2)];
            $loan = $loan->fresh();
            if ($loan->status !== 'active') {
                continue;
            }
            $roll = mt_rand(1, 10);
            if ($roll <= 2) {
                $this->charge($loan, 'interest', mt_rand(1, 20_000), "prop:{$seed}:i{$step}");
            } elseif ($roll === 3) {
                $this->charge($loan, 'penalty', mt_rand(1, 3_000), "prop:{$seed}:p{$step}");
            } elseif ($roll === 4) {
                DB::table('sacco_loans')->where('id', $loan->id)->update(['interest_in_suspense_minor' => DB::raw('interest_in_suspense_minor + '.mt_rand(1, 5_000))]);
            } else {
                $amount = mt_rand(1, 10) === 1 ? $loan->owedMinor() + 50_000 : mt_rand(1, 150_000);
                $channel = mt_rand(0, 2) === 0 ? 'internal' : 'cash';
                $code = $this->errorCode(fn () => (new Repayments())->record($company, (int) $loan->id, ['amount_minor' => $amount, 'channel' => $channel] + ($channel === 'internal' ? ['savings_account_id' => $acct->id] : []), $teller));
                $this->assertContains($code, ['none', 'insufficient_available'], "step {$step}");
            }
            $loan->refresh();
            $this->assertGreaterThanOrEqual(0, $loan->principal_outstanding_minor);
            $this->assertGreaterThanOrEqual(0, $loan->interest_in_suspense_minor);
            $this->assertSame($loan->owedMinor() === 0, $loan->status === 'closed', "step {$step}: closed exactly when nothing is owed");
            $this->verified($cid);
        }
        foreach ($loans as [$loan]) {
            $paid = (int) SaccoLoanRepayment::where('loan_id', $loan->id)->where('status', 'posted')->sum('principal_minor');
            $this->assertSame((int) $loan->approved_minor - $paid, (int) $loan->fresh()->principal_outstanding_minor, 'principal = lent − principal repaid');
        }
    }
}

<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanChange;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoSavingsAccount;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LoanPostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write-off and recovery (04 §5.1, §7 `write_off`; SI 51 reg. 44, AK-40). A loans officer asks; two checkers, each
 * holding the chair's, vice chair's or treasurer's office, approve. Executing takes what is left on 1300, 1320, 1330
 * and 1340 off the books against the allowance (1390) and, for any loss not yet provided for, 5200; suspended
 * interest and penalty are dropped with it (kept in the loan's history). The loan stays in the written-off register:
 * money later recovered (from the borrower in cash or bank, or a guarantor's held savings) is income in 4500. Office
 * mode only.
 */
final class WriteOffs
{
    public function request(Company $company, int $loanId, User $maker, ?string $reason = null, ?string $uuid = null): SaccoApproval
    {
        SaccoAccess::authorize($maker, 'sacco_loans');
        $cid = (int) $company->id;
        if (blank($reason) && LoanBooks::strict($cid)) {
            throw BusinessRuleException::make('reason_required', 'Say why the loan is written off.');
        }
        if (LoanBooks::settings($cid)->isGroup()) {
            throw BusinessRuleException::make('not_in_group_mode', 'A savings group settles a bad loan at share-out, not by write-off.');
        }
        $loan = LoanBooks::find($cid, $loanId);
        LoanBooks::assertStatus($loan, ['active'], 'written off');

        return (new SaccoApprovals())->request($company, 'write_off', ['loan_id' => (int) $loan->id, 'loan_no' => $loan->loan_no, 'member_id' => (int) $loan->member_id,
            'reason' => mb_substr((string) $reason, 0, 255), 'classification' => $loan->classification, 'days_past_due' => (int) $loan->days_past_due,
            'amount_minor' => (int) $loan->principal_outstanding_minor + (int) $loan->interest_due_minor + (int) $loan->penalty_due_minor + (int) $loan->fees_due_minor], $maker, $uuid ?? (string) Str::uuid());
    }

    /** @return array<string, mixed> */
    public function execute(SaccoApproval $approval, User $checker): array
    {
        $cid = (int) $approval->company_id;
        SaccoTransactions::lockHead($cid);
        $loan = LoanBooks::lock($cid, (int) $approval->payload['loan_id']);
        LoanBooks::assertStatus($loan, ['active'], 'written off');
        $left = ['principal' => max(0, (int) $loan->principal_outstanding_minor), 'interest' => max(0, (int) $loan->interest_due_minor),
            'penalty' => max(0, (int) $loan->penalty_due_minor), 'fees' => max(0, (int) $loan->fees_due_minor)];
        $total = array_sum($left);
        $today = LoanBooks::today($cid);
        $entryId = null;
        if ($total > 0) {
            $allowance = (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, 'loans.allowance'))->value('balance_minor');
            $entryId = (int) (new PostingService())->post((new LoanPostingRules($cid, 'office'))->writeOff('apr:'.$approval->uuid, $loan, $left, Amount::of($allowance, (string) $loan->currency), (string) $loan->currency)
                ->by((int) $checker->id)->approval((int) $approval->id)->source('sacco_loans', (int) $loan->id)->memo('Write-off, loan '.$loan->loan_no))->id;
        }
        $suspense = ['interest_in_suspense_minor' => (int) $loan->interest_in_suspense_minor, 'penalty_in_suspense_minor' => (int) $loan->penalty_in_suspense_minor];
        DB::table('sacco_loans')->where('id', $loan->id)->update(['interest_in_suspense_minor' => 0, 'penalty_in_suspense_minor' => 0]);
        $loan->refresh()->fill(['status' => 'written_off', 'written_off_on' => $today->toDateString(), 'written_off_minor' => $total])->save();
        SaccoLoanChange::create(['company_id' => $cid, 'loan_id' => $loan->id, 'kind' => 'write_off', 'before' => $left + $suspense, 'amount_minor' => $total,
            'reason' => $approval->payload['reason'] ?? null, 'approval_id' => $approval->id, 'entry_id' => $entryId, 'effective_on' => $today->toDateString(), 'created_by_id' => $checker->id]);
        SaccoAudit::record('sacco.loan.written_off', $loan, $cid, ['amount_minor' => $total, 'entry_id' => $entryId], (int) $checker->id);

        return ['loan_id' => (int) $loan->id, 'entry_id' => $entryId, 'written_off_minor' => $total];
    }

    /**
     * Money recovered on a written-off loan, from the borrower (cash or bank): Dr cash or bank, Cr 4500. A receipt.
     *
     * @param  array<string, mixed>  $data  uuid, amount_minor, channel (cash|bank), value_date, reference
     */
    public function recover(Company $company, int $loanId, array $data, User $by): SaccoLoanChange
    {
        SaccoAccess::authorize($by, 'sacco_teller');
        $cid = (int) $company->id;
        $uuid = strtolower((string) ($data['uuid'] ?? Str::uuid()));
        $amount = $data['amount_minor'] ?? null;
        if (! is_int($amount) || $amount < 1) {
            throw BusinessRuleException::make('invalid_amount', 'Give the amount recovered in whole minor units.');
        }

        return DB::transaction(function () use ($company, $cid, $loanId, $data, $by, $uuid, $amount) {
            SaccoTransactions::lockHead($cid);
            $loan = LoanBooks::lock($cid, $loanId);
            $hit = \App\Models\SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->sharedLock()->first();
            if ($hit !== null) {
                return SaccoLoanChange::withoutGlobalScopes()->where('loan_id', $loan->id)->where('kind', 'recovery')->where('entry_id', $hit->journal_entry_id)->firstOrFail();
            }

            return $this->recoverInto($company, $loan, $amount, null, ['uuid' => $uuid] + $data, $by, null)['change'];
        });
    }

    /**
     * Post a recovery (inside a transaction, the head locked): from cash or bank, or a guarantor's savings.
     *
     * @param  array<string, mixed>  $d
     * @return array{change: SaccoLoanChange, repayment: null, transaction: \App\Models\SaccoTransaction}
     */
    public function recoverInto(Company $company, SaccoLoan $loan, int $amount, ?SaccoSavingsAccount $fromSavings, array $d, User $by, ?SaccoLoanGuarantor $guarantor): array
    {
        $cid = (int) $company->id;
        LoanBooks::assertStatus($loan, ['written_off'], 'recovered from');
        $still = (int) $loan->written_off_minor - (int) $loan->recovered_minor;
        if ($amount > $still) {
            throw BusinessRuleException::make('recovery_exceeds', "Only {$still} is still to recover on this loan.", ['remaining_minor' => $still]);
        }
        $channel = (string) ($d['channel'] ?? 'cash');
        $rules = new LoanPostingRules($cid, LoanBooks::settings($cid)->mode);
        $value = Carbon::parse($d['value_date'] ?? LoanBooks::today($cid));
        [$entryDate, $valueDate] = LoanBooks::dates($cid, $value);
        $draft = $rules->recovery('txn:'.$d['uuid'], $loan, Amount::of($amount, (string) $loan->currency), $fromSavings !== null ? MemberFunds::ref($fromSavings) : null,
            $fromSavings === null ? $rules->channelAccount($channel) : null);
        $stx = new SaccoTransactions();
        $tx = $stx->document($cid, ['uuid' => $d['uuid'], 'kind' => 'loan_repayment', 'channel' => $fromSavings !== null ? 'internal' : $channel,
            'member_id' => $fromSavings !== null ? (int) $fromSavings->member_id : (int) $loan->member_id, 'savings_account_id' => $fromSavings?->id, 'loan_id' => (int) $loan->id,
            'amount_minor' => $amount, 'value_date' => $valueDate, 'reference' => $d['reference'] ?? null, 'narration' => ($d['narration'] ?? 'Recovery').' on written-off loan '.$loan->loan_no],
            $by, ['entry_date' => $entryDate], 'posted');
        $tx = $stx->post($tx, $draft, (int) $by->id, ['entry_date' => $entryDate]);
        $loan->fill(['recovered_minor' => (int) $loan->recovered_minor + $amount])->save();
        $change = SaccoLoanChange::create(['company_id' => $cid, 'loan_id' => $loan->id, 'kind' => 'recovery', 'amount_minor' => $amount, 'entry_id' => $tx->journal_entry_id,
            'after' => ['recovered_minor' => (int) $loan->recovered_minor, 'guarantor_id' => $guarantor?->id], 'effective_on' => $valueDate, 'created_by_id' => $by->id,
            'reason' => $guarantor !== null ? 'From a guarantor\'s held savings' : 'From the borrower']);
        if ((int) $loan->recovered_minor >= (int) $loan->written_off_minor) {
            (new Guarantors())->releaseAll($loan, 'The written-off loan is fully recovered.', (int) $by->id);
        }
        SaccoAudit::record('sacco.loan.recovered', $loan, $cid, ['amount_minor' => $amount], (int) $by->id);

        return ['change' => $change, 'repayment' => null, 'transaction' => $tx];
    }
}

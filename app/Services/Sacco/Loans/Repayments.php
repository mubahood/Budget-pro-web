<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoLoanRepayment;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSetting;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\Sacco\Ledger\LoanPostingRules;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Money into a loan (04 §5.1, §5.3, P2.10; AK-27, AK-39, SI 108 reg. 12).
 *
 * - Every repayment is a receipt (sacco_transactions kind `loan_repayment`, through SaccoTransactions::record, so it
 *   is idempotent on its uuid, takes the ledger lock first, and a phone can push a cash one offline).
 * - It is split in the SACCO's allocation order (default penalty → fees → interest → principal): penalty and
 *   interest owed (1330, 1320) before what is in suspense (recognised straight to 4110/4100 as it is received);
 *   whatever is left after everything owed goes to the member's savings, or to 2150 when they have none.
 * - A payment that clears the loan also pays interest to the day for the running period (outstanding × yearly rate
 *   × days ÷ 365) and nothing more: there is no early-settlement fee. A part-payment ahead of time reduces the
 *   principal, so the next period's interest is lower.
 * - When principal, interest, penalty, fees and suspense are all zero the loan closes on that day, interest stops
 *   for good (the MobiSave bug), and its guarantors and collateral are released.
 * - A guarantor's held savings pay through apply() too (Loans\Recoveries, P2.12), never more than the loan owes.
 */
final class Repayments
{
    /**
     * Record a repayment through the receipts (the teller's, the API's and the portal's path).
     *
     * @param  array<string, mixed>  $data  uuid, amount_minor, channel (cash|bank|internal = from savings), savings_account_id, value_date, reference, narration
     * @param  array<string, mixed>  $opts  entry_date (history), settle
     */
    public function record(Company $company, int $loanId, array $data, ?User $by, array $opts = []): SaccoLoanRepayment
    {
        $data = ['kind' => 'loan_repayment', 'loan_id' => $loanId] + $data;
        if (($data['channel'] ?? null) === 'savings') {
            $data['channel'] = 'internal';
        }
        $r = app(SaccoTransactions::class)->record($company, $data, $by, $opts);

        return SaccoLoanRepayment::withoutGlobalScopes()->where('company_id', $company->id)->where('transaction_id', $r['transaction']->id)->firstOrFail();
    }

    /** SaccoTransactions' recorder for `loan_repayment` (inside record()'s transaction, the head locked). @param array<string, mixed> $d @param array<string, mixed> $opts */
    public static function recorder(Company $company, array $d, ?User $by, array $opts, SaccoSetting $settings): SaccoTransaction
    {
        $cid = (int) $company->id;
        // A twin with the same uuid may have committed while this one waited for the ledger lock: a locking read sees it.
        $twin = SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', strtolower((string) $d['uuid']))->sharedLock()->first();
        if ($twin !== null) {
            return $twin;
        }
        $loan = LoanBooks::lock($cid, (int) ($d['loan_id'] ?? 0));
        $channel = (string) ($d['channel'] ?? 'cash');
        $source = null;
        if ($channel === 'internal') {
            $source = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find((int) ($d['savings_account_id'] ?? 0))
                ?? throw BusinessRuleException::make('account_not_found', 'Choose the savings account the repayment comes from.');
            if ((int) $source->member_id !== (int) $loan->member_id) {
                throw BusinessRuleException::make('account_not_found', 'A loan is repaid from the borrower\'s own savings (a guarantor\'s pays only by recovery).');
            }
        }

        return (new self())->apply($company, $loan, (int) $d['amount_minor'], $channel, $source, $d, $by, $opts, 'repayment')['transaction'];
    }

    /** Hold an offline repayment a phone could not place (SaccoTransactions::offlineCheck): unknown or no longer open. @return array{0: ?string, 1: null} */
    public static function offlineCheck(int $companyId, array $record, ?SaccoMember $member): array
    {
        $loan = isset($record['loan_id']) ? SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->find((int) $record['loan_id']) : null;
        if ($loan === null || ($member !== null && (int) $loan->member_id !== (int) $member->id)) {
            return ['unknown_account', null];
        }

        return [$loan->status === 'active' ? null : 'loan_closed', null];
    }

    /**
     * The settlement quote on a day: what clears the loan (no early-settlement fee).
     *
     * @return array<string, mixed>
     */
    public function quote(int $companyId, int $loanId, ?string $on = null): array
    {
        $loan = LoanBooks::find($companyId, $loanId);
        $day = Carbon::parse($on ?? LoanBooks::today($companyId))->startOfDay();

        return Schedule::settlementQuote(self::owed($loan), $loan->loanTerms(), self::periodStart($loan, $day), $day, (string) $loan->currency) + ['loan_id' => (int) $loan->id, 'on' => $day->toDateString()];
    }

    /**
     * Split an amount in the allocation order. $toDay is the running period's interest, charged only when the payment
     * clears everything else.
     *
     * @param  array{principal: int, interest: int, interest_suspense: int, penalty: int, penalty_suspense: int, fees: int}  $owed
     * @param  list<string>  $order
     * @return array{penalty: int, penalty_suspense: int, fees: int, interest: int, interest_suspense: int, interest_to_day: int, principal: int, overpayment: int}
     */
    public static function split(int $amount, array $owed, array $order, int $toDay): array
    {
        $everything = array_sum($owed);
        $toDay = $amount >= $everything ? $toDay : 0;
        $buckets = ['penalty' => ['penalty' => $owed['penalty'], 'penalty_suspense' => $owed['penalty_suspense']], 'fees' => ['fees' => $owed['fees']],
            'interest' => ['interest' => $owed['interest'], 'interest_suspense' => $owed['interest_suspense'], 'interest_to_day' => $toDay], 'principal' => ['principal' => $owed['principal']]];
        $out = ['penalty' => 0, 'penalty_suspense' => 0, 'fees' => 0, 'interest' => 0, 'interest_suspense' => 0, 'interest_to_day' => 0, 'principal' => 0, 'overpayment' => 0];
        $left = $amount;
        foreach ($order as $bucket) {
            foreach ($buckets[$bucket] as $part => $due) {
                $take = min($left, max(0, $due));
                $out[$part] = $take;
                $left -= $take;
            }
        }
        $out['overpayment'] = $left;

        return $out;
    }

    /** @return array{principal: int, interest: int, interest_suspense: int, penalty: int, penalty_suspense: int, fees: int} */
    public static function owed(SaccoLoan $loan): array
    {
        return ['principal' => max(0, (int) $loan->principal_outstanding_minor), 'interest' => max(0, (int) $loan->interest_due_minor),
            'interest_suspense' => (int) $loan->interest_in_suspense_minor, 'penalty' => max(0, (int) $loan->penalty_due_minor),
            'penalty_suspense' => (int) $loan->penalty_in_suspense_minor, 'fees' => max(0, (int) $loan->fees_due_minor)];
    }

    /** Where the running period began: the latest due date already charged on or before $day, else the payout day. */
    public static function periodStart(SaccoLoan $loan, Carbon $day): Carbon
    {
        $due = SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)
            ->whereNotNull('interest_charged_minor')->where('due_on', '<=', $day->toDateString())->max('due_on');
        $start = $due !== null ? Carbon::parse($due) : Carbon::parse($loan->disbursed_on ?? $day);
        $changed = $loan->reschedule_count > 0 ? \App\Models\SaccoLoanChange::withoutGlobalScopes()->where('loan_id', $loan->id)->where('kind', 'reschedule')->max('effective_on') : null;

        return $changed !== null && Carbon::parse($changed)->gt($start) ? Carbon::parse($changed) : $start;
    }

    /**
     * Allocate, post (with its receipt) and keep the caches. Inside a transaction with the ledger head locked.
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $opts
     * @return array{transaction: SaccoTransaction, repayment: SaccoLoanRepayment}
     */
    public function apply(Company $company, SaccoLoan $loan, int $amount, string $channel, ?SaccoSavingsAccount $source, array $d, ?User $by, array $opts, string $kind,
        ?SaccoLoanGuarantor $guarantor = null): array
    {
        $cid = (int) $company->id;
        if ($loan->status === 'written_off') {
            throw BusinessRuleException::make('loan_written_off', 'This loan is written off: record the money as a recovery.');
        }
        if ($loan->status !== 'active') {
            throw BusinessRuleException::make('loan_closed', "A loan that is {$loan->status} takes no repayment.");
        }
        if ($amount < 1) {
            throw BusinessRuleException::make('invalid_amount', 'A repayment is for more than nothing.');
        }
        $uuid = strtolower((string) ($d['uuid'] ?? Str::uuid()));
        $value = Carbon::parse($d['value_date'] ?? LoanBooks::today($cid))->startOfDay();
        if ($loan->disbursed_on !== null && $value->lt($loan->disbursed_on)) {
            throw BusinessRuleException::make('invalid_value_date', 'A repayment cannot come before the loan was paid out.');
        }
        $settings = LoanBooks::settings($cid);
        $terms = $loan->loanTerms();
        $owed = self::owed($loan);
        $toDay = Schedule::interestToDay($owed['principal'], $terms, self::periodStart($loan, $value), $value, (string) $loan->currency);
        if (! empty($opts['settle']) && $amount < array_sum($owed) + $toDay) {
            throw BusinessRuleException::make('settlement_short', 'Settling today takes '.(array_sum($owed) + $toDay).'.', ['quote_minor' => array_sum($owed) + $toDay]);
        }
        $split = self::split($amount, $owed, $terms->allocationOrder, $toDay);
        $overTo = null;
        if ($split['overpayment'] > 0) {
            $overTo = MemberFunds::payoutAccount($cid, (int) $loan->member_id, $kind === 'repayment' && $source !== null ? (int) $source->id : null);
            if ($kind === 'guarantor_offset') {
                $overTo = null;
                $amount -= $split['overpayment']; // a guarantor's money never pays more than the loan owes
                $split['overpayment'] = 0;
            }
        }
        $rules = new LoanPostingRules($cid, $settings->mode);
        $money = Amount::of($amount, (string) $loan->currency);
        $entryDate = $opts['entry_date'] ?? null;
        [$entryDay, $valueDay] = $entryDate !== null ? [$entryDate, $value->toDateString()] : LoanBooks::dates($cid, $value);
        $draft = $rules->repayment('txn:'.$uuid, $kind === 'guarantor_offset' ? 'loan_repayment' : 'loan_repayment', $loan, $money, $source !== null ? MemberFunds::ref($source) : null,
            $source === null ? $rules->channelAccount($channel === 'guarantor' ? 'cash' : $channel) : null, $split, $overTo !== null ? MemberFunds::ref($overTo) : null);
        if ($source !== null && $kind === 'repayment') {
            $draft->guard(function () use ($cid, $source, $amount) {
                if (SavingsAccounts::available($cid, (int) $source->id, true) < $amount) {
                    throw BusinessRuleException::make('insufficient_available', 'The savings account does not have that much available.');
                }
            });
        }
        $stx = new SaccoTransactions();
        $existing = SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        $tx = $existing ?? $stx->document($cid, ['uuid' => $uuid, 'kind' => 'loan_repayment', 'channel' => in_array($channel, ['cash', 'bank'], true) ? $channel : 'internal',
            'member_id' => (int) $loan->member_id, 'savings_account_id' => $source?->id, 'loan_id' => (int) $loan->id, 'amount_minor' => $amount, 'value_date' => $valueDay,
            'reference' => $d['reference'] ?? null, 'narration' => $d['narration'] ?? ('Loan '.$loan->loan_no.' repayment')], $by, $opts + ['entry_date' => $entryDay], 'posted');
        $tx = $stx->post($tx, $draft, $by?->id, ['entry_date' => $entryDay]);
        if ($split['interest_suspense'] > 0 || $split['penalty_suspense'] > 0) {
            DB::table('sacco_loans')->where('id', $loan->id)->update(['interest_in_suspense_minor' => DB::raw('interest_in_suspense_minor - '.$split['interest_suspense']),
                'penalty_in_suspense_minor' => DB::raw('penalty_in_suspense_minor - '.$split['penalty_suspense']), 'updated_at' => now()]);
        }
        $repayment = SaccoLoanRepayment::create(['company_id' => $cid, 'loan_id' => $loan->id, 'uuid' => $uuid, 'kind' => $kind, 'channel' => $kind === 'guarantor_offset' ? 'guarantor' : ($source !== null ? 'savings' : $channel),
            'source_savings_account_id' => $source?->id, 'guarantor_id' => $guarantor?->id, 'amount_minor' => $amount, 'penalty_minor' => $split['penalty'], 'penalty_suspense_minor' => $split['penalty_suspense'],
            'fees_minor' => $split['fees'], 'interest_minor' => $split['interest'], 'interest_suspense_minor' => $split['interest_suspense'], 'interest_to_day_minor' => $split['interest_to_day'],
            'principal_minor' => $split['principal'], 'overpayment_minor' => $split['overpayment'], 'overpayment_savings_account_id' => $overTo?->id, 'value_date' => $valueDay,
            'schedule_version' => (int) $loan->schedule_version, 'journal_entry_id' => $tx->journal_entry_id, 'transaction_id' => $tx->id, 'reference' => $d['reference'] ?? null,
            'narration' => $d['narration'] ?? null, 'received_by_id' => $by?->id, 'member_actor_id' => $opts['member_actor_id'] ?? null]);
        $loan = LoanBooks::lock($cid, (int) $loan->id);
        self::refreshLines($loan, $valueDay);
        $closed = self::closeIfPaid($loan, $valueDay, $by?->id);
        LoanNotices::toMember($cid, (int) $loan->member_id, 'receipt_repayment', ['loan' => (string) $loan->loan_no, 'amount' => LoanBooks::fmt($cid, $amount),
            'outstanding' => LoanBooks::fmt($cid, $loan->fresh()->owedMinor()), 'receipt' => (string) $tx->receipt_no]);
        if ($closed) {
            LoanNotices::toMember($cid, (int) $loan->member_id, 'loan_closed', ['loan' => (string) $loan->loan_no]);
        }

        return ['transaction' => $tx, 'repayment' => $repayment];
    }

    /** A repayment's receipt was reversed (SaccoTransactions::onReverse): the allocation is marked reversed and the caches follow. */
    public static function reversed(SaccoTransaction $original, SaccoTransaction $reversal): void
    {
        $r = SaccoLoanRepayment::withoutGlobalScopes()->where('company_id', $original->company_id)->where('transaction_id', $original->id)->lockForUpdate()->first();
        if ($r === null) {
            // A recovery on a written-off loan: its recovered total falls back.
            $change = \App\Models\SaccoLoanChange::withoutGlobalScopes()->where('company_id', $original->company_id)->where('kind', 'recovery')
                ->where('entry_id', $original->journal_entry_id)->first();
            if ($change !== null) {
                DB::table('sacco_loans')->where('id', $change->loan_id)->update(['recovered_minor' => DB::raw('IF(recovered_minor > '.(int) $change->amount_minor.', recovered_minor - '.(int) $change->amount_minor.', 0)')]);
            }

            return;
        }
        if ($r->status !== 'posted') {
            return;
        }
        $r->update(['status' => 'reversed', 'reversal_entry_id' => $reversal->journal_entry_id]);
        if ($r->interest_suspense_minor > 0 || $r->penalty_suspense_minor > 0) {
            DB::table('sacco_loans')->where('id', $r->loan_id)->update(['interest_in_suspense_minor' => DB::raw('interest_in_suspense_minor + '.(int) $r->interest_suspense_minor),
                'penalty_in_suspense_minor' => DB::raw('penalty_in_suspense_minor + '.(int) $r->penalty_suspense_minor), 'updated_at' => now()]);
        }
        $loan = SaccoLoan::withoutGlobalScopes()->lockForUpdate()->findOrFail($r->loan_id);
        if ($loan->status === 'closed') {
            $loan->fill(['status' => 'active', 'closed_on' => null])->save(); // reopened: the money it closed on was reversed
        }
        self::refreshLines($loan, $reversal->value_date->toDateString());
    }

    /**
     * The schedule's paid columns from the posted allocations of the current version, filled in due order (principal
     * against principal, interest against the interest charged or scheduled).
     */
    public static function refreshLines(SaccoLoan $loan, string $on): void
    {
        $sums = SaccoLoanRepayment::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)->where('status', 'posted')
            ->selectRaw('COALESCE(SUM(principal_minor),0) p, COALESCE(SUM(interest_minor + interest_suspense_minor + interest_to_day_minor),0) i')->first();
        $p = (int) $sums->p;
        $i = (int) $sums->i;
        $lines = SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)->orderBy('installment_no')->lockForUpdate()->get();
        foreach ($lines as $line) {
            $pp = min($p, (int) $line->principal_due_minor);
            $p -= $pp;
            $ip = min($i, $line->interestOwedMinor());
            $i -= $ip;
            $paid = $pp >= (int) $line->principal_due_minor && $ip >= $line->interestOwedMinor() && $line->interest_charged_minor !== null;
            $status = $paid ? 'paid' : ($pp + $ip > 0 ? 'partial' : 'due');
            if ($line->status === 'superseded') {
                continue;
            }
            $line->fill(['principal_paid_minor' => $pp, 'interest_paid_minor' => $ip, 'status' => $status, 'paid_on' => $paid ? ($line->paid_on?->toDateString() ?? $on) : null]);
            if ($line->isDirty()) {
                $line->save();
            }
        }
    }

    /** Close a loan once nothing is owed (ledger and suspense): interest stops for good. */
    public static function closeIfPaid(SaccoLoan $loan, string $on, ?int $byUserId = null): bool
    {
        $loan->refresh();
        if ($loan->status !== 'active' || $loan->owedMinor() !== 0) {
            return false;
        }
        SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)->where('status', '!=', 'paid')
            ->update(['status' => 'paid', 'paid_on' => $on, 'updated_at' => now()]);
        $loan->fill(['status' => 'closed', 'closed_on' => $on, 'arrears_principal_minor' => 0, 'arrears_interest_minor' => 0, 'days_past_due' => 0, 'instalments_in_arrears' => 0])->save();
        (new Guarantors())->releaseAll($loan, 'The loan is fully paid.', $byUserId);
        Collateral::releaseAll((int) $loan->company_id, (int) $loan->id, $on);
        SaccoAudit::record('sacco.loan.closed', $loan, (int) $loan->company_id, ['closed_on' => $on], $byUserId);

        return true;
    }
}

<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanChange;
use App\Models\SaccoLoanRepayment;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoPayout;
use App\Models\User;
use App\Services\Sacco\Ledger\LoanPostingRules;
use App\Services\Sacco\Payouts\PayoutService;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paying a loan out, and the cooling-off cancellation (04 §5.3, §7 `loan_disbursement`, P2.9; SI 108 reg. 11, AK-10,
 * AK-26). An officer with sacco_disburse asks; another with sacco_approve, never the loan's appraiser, approves; the
 * approval executes the payout in its own transaction (key `apr:{uuid}`, so executing twice posts once):
 *
 * - to the member's savings account, in cash, by bank, or by MoMo through the payout interface (2160 in transit
 *   until the provider confirms; a failure reverses it and the loan goes back to signed);
 * - the processing fee and insurance are taken from what is paid out;
 * - the schedule is made from the first due date (one period after payout unless the officer chose another, up to
 *   two periods), and the loan is active.
 *
 * Within its cooling-off (5 working days after signing in office mode) the borrower may cancel by returning what they
 * received and the cancellation fee (at most 2 %); no interest is charged and the fee and insurance are given back.
 */
final class Disbursement
{
    /**
     * Ask for the payout.
     *
     * @param  array<string, mixed>  $data  channel (savings|cash|bank|momo), savings_account_id, momo_phone_e164, bank_reference, disburse_on, first_due_on
     */
    public function request(Company $company, int $loanId, User $maker, array $data, ?string $uuid = null): SaccoApproval
    {
        SaccoAccess::authorize($maker, 'sacco_disburse');
        $cid = (int) $company->id;
        $loan = LoanBooks::find($cid, $loanId);
        LoanBooks::assertStatus($loan, ['signed'], 'paid out');
        $channel = (string) ($data['channel'] ?? $loan->disbursement_channel ?? '');
        $product = \App\Models\SaccoLoanProduct::withoutGlobalScopes()->findOrFail($loan->product_id);
        if (! in_array($channel, (array) $product->allowed_channels, true)) {
            throw BusinessRuleException::make('channel_not_allowed', "{$product->name} is not paid out by {$channel}.", ['allowed' => $product->allowed_channels]);
        }
        if ($channel === 'momo' && ! PayoutService::enabled()) {
            PayoutService::gateway(); // throws payouts_off
        }
        $payload = ['loan_id' => (int) $loan->id, 'loan_no' => $loan->loan_no, 'member_id' => (int) $loan->member_id, 'amount_minor' => (int) $loan->approved_minor, 'channel' => $channel,
            'savings_account_id' => $channel === 'savings' ? (int) (MemberFunds::payoutAccount($cid, (int) $loan->member_id, isset($data['savings_account_id']) ? (int) $data['savings_account_id'] : ($loan->disburse_to_savings_account_id === null ? null : (int) $loan->disburse_to_savings_account_id))?->id
                ?? throw BusinessRuleException::make('account_not_found', 'The member has no open savings account to pay the loan into.')) : null,
            'momo_phone_e164' => $channel === 'momo' ? (string) ($data['momo_phone_e164'] ?? $loan->momo_phone_e164 ?? throw BusinessRuleException::make('invalid_phone', 'Give the mobile-money number.')) : null,
            'bank_reference' => $data['bank_reference'] ?? null, 'disburse_on' => $data['disburse_on'] ?? null, 'first_due_on' => $data['first_due_on'] ?? null];

        return (new SaccoApprovals())->request($company, 'loan_disbursement', $payload, $maker, $uuid ?? (string) Str::uuid());
    }

    /** The executor's work (inside the approval's transaction). @return array<string, mixed> */
    public function execute(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        $cid = (int) $approval->company_id;
        SaccoTransactions::lockHead($cid);
        $loan = LoanBooks::lock($cid, (int) $p['loan_id']);
        LoanBooks::assertStatus($loan, ['signed'], 'paid out');
        $settings = LoanBooks::settings($cid);
        $terms = $loan->loanTerms();
        $today = LoanBooks::today($cid);
        $on = isset($p['disburse_on']) ? Carbon::parse($p['disburse_on'])->startOfDay() : $today;
        if ($on->gt($today) || ($loan->signed_on !== null && $on->lt($loan->signed_on))) {
            throw BusinessRuleException::make('invalid_date', 'A loan is paid out on or after the day it is signed, never in the future.');
        }
        if ($terms->holdDisbursementUntilCoolingOffEnds && $loan->cooling_off_ends_on !== null && $on->lte($loan->cooling_off_ends_on)) {
            throw BusinessRuleException::make('cooling_off_active', 'This product pays out only after the cooling-off, which ends on '.$loan->cooling_off_ends_on->toDateString().'.');
        }
        $loan->disbursement_channel = (string) $p['channel'];
        (new Eligibility())->assert($loan, 'disburse');
        $first = isset($p['first_due_on']) ? Carbon::parse($p['first_due_on'])->startOfDay() : Schedule::firstDueAfter($on, $terms->frequency);
        $latest = Schedule::firstDueAfter(Schedule::firstDueAfter($on, $terms->frequency), $terms->frequency);
        if ($first->lte($on) || $first->gt($latest)) {
            throw BusinessRuleException::make('invalid_first_due', 'The first instalment falls after the payout and within two periods of it.');
        }
        $principal = (int) $p['amount_minor'];
        $currency = (string) $loan->currency;
        $lines = Schedule::build($principal, $terms, $first, $currency);
        $charges = Schedule::upfrontCharges($principal, $terms, $currency);
        $money = fn (int $minor) => Amount::of($minor, $currency);
        $rules = new LoanPostingRules($cid, $settings->mode);
        [$entryDate, $valueDate] = LoanBooks::dates($cid, $on);
        $key = 'apr:'.$approval->uuid;
        $version = (int) $loan->schedule_version;
        foreach ($lines as $l) {
            SaccoLoanScheduleLine::create(['company_id' => $cid, 'loan_id' => $loan->id, 'schedule_version' => $version, 'installment_no' => $l->no, 'due_on' => $l->dueOn,
                'opening_principal_minor' => $l->openingPrincipal, 'principal_due_minor' => $l->principal, 'interest_due_minor' => $l->interest, 'fee_due_minor' => $l->fee]);
        }
        $net = $principal - $charges['fee'] - $charges['insurance'];
        $channel = (string) $p['channel'];
        $entryId = null;
        $payoutId = null;
        $savings = null;
        if ($channel === 'momo') {
            // The loan waits in `disbursing` before the payout exists: the provider's answer may come at once.
            $loan->fill(['status' => 'disbursing', 'disbursed_on' => $on->toDateString(), 'first_due_on' => $first->toDateString(), 'maturity_on' => end($lines)->dueOn,
                'disbursement_approval_id' => $approval->id, 'momo_phone_e164' => $p['momo_phone_e164'], 'arrears_on' => $on->toDateString()])->save();
            $payout = (new PayoutService())->create($cid, 'loan_disbursement', 'sacco_loans', (int) $loan->id, (int) $loan->member_id, (string) $p['momo_phone_e164'], $net,
                (int) $approval->id, 'loan:'.$loan->id.':v'.$version.':'.$approval->uuid,
                fn (string $k) => $rules->disbursement($k, $loan, $money($principal), $money($charges['fee']), $money($charges['insurance']), null, $rules->account('payouts.in_transit'))
                    ->by((int) $checker->id)->approval((int) $approval->id)->memo('Loan '.$loan->loan_no.' sent by mobile money'), $entryDate);
            $loan = LoanBooks::lock($cid, (int) $loan->id);
            if ($loan->status !== 'signed') {
                $loan->fill(['payout_id' => $payout->id, 'disbursement_entry_id' => $payout->sent_journal_id])->save();
            }
            SaccoAudit::record('sacco.loan.payout_sent', $loan, $cid, ['payout_id' => $payout->id, 'net_minor' => $net], (int) $checker->id);

            return ['loan_id' => (int) $loan->id, 'entry_id' => (int) $payout->sent_journal_id, 'payout_id' => (int) $payout->id, 'status' => $loan->status];
        } else {
            $savings = $channel === 'savings' ? MemberFunds::payoutAccount($cid, (int) $loan->member_id, (int) $p['savings_account_id']) : null;
            $draft = $rules->disbursement($key, $loan, $money($principal), $money($charges['fee']), $money($charges['insurance']),
                $savings !== null ? MemberFunds::ref($savings) : null, $savings === null ? $rules->channelAccount($channel) : null)->by((int) $checker->id)->approval((int) $approval->id);
            $stx = new SaccoTransactions();
            $tx = $stx->document($cid, ['uuid' => (string) Str::uuid(), 'kind' => 'loan_disbursement', 'channel' => $channel === 'savings' ? 'internal' : $channel,
                'member_id' => (int) $loan->member_id, 'savings_account_id' => $savings?->id, 'loan_id' => (int) $loan->id, 'amount_minor' => $net,
                'fee_minor' => $charges['fee'] + $charges['insurance'], 'value_date' => $valueDate, 'reference' => $p['bank_reference'] ?? null,
                'narration' => 'Loan '.$loan->loan_no.' paid out'], $checker, ['entry_date' => $entryDate], 'posted');
            $tx = $stx->post($tx, $draft, (int) $checker->id, ['entry_date' => $entryDate]);
            $entryId = (int) $tx->journal_entry_id;
        }
        $loan->fill(['status' => $channel === 'momo' ? 'disbursing' : 'active', 'disbursed_on' => $on->toDateString(), 'first_due_on' => $first->toDateString(),
            'maturity_on' => end($lines)->dueOn, 'disbursement_approval_id' => $approval->id, 'disbursement_entry_id' => $entryId, 'payout_id' => $payoutId,
            'disburse_to_savings_account_id' => $savings?->id, 'momo_phone_e164' => $p['momo_phone_e164'] ?? $loan->momo_phone_e164, 'arrears_on' => $on->toDateString()])->save();
        SaccoAudit::record('sacco.loan.disbursed', $loan, $cid, ['channel' => $channel, 'net_minor' => $net, 'entry_id' => $entryId], (int) $checker->id);
        if ($channel !== 'momo') {
            $this->tellDisbursed($loan, $net, $lines[0]->instalment(), $lines[0]->dueOn);
        }

        return ['loan_id' => (int) $loan->id, 'entry_id' => $entryId, 'payout_id' => $payoutId, 'status' => $loan->status];
    }

    /** A MoMo payout's final answer (PayoutService handler): active on success; back to signed, schedule dropped, on failure. */
    public static function payoutResult(SaccoPayout $payout, string $status): void
    {
        $loan = SaccoLoan::withoutGlobalScopes()->where('company_id', $payout->company_id)->lockForUpdate()->find($payout->subject_id);
        if ($loan === null || $loan->status !== 'disbursing') {
            return;
        }
        if ($status === 'successful') {
            $loan->fill(['status' => 'active'])->save();
            $line = SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)->orderBy('installment_no')->first();
            (new self())->tellDisbursed($loan, (int) $payout->amount_minor, $line === null ? 0 : (int) $line->principal_due_minor + (int) $line->interest_due_minor, (string) $line?->due_on?->toDateString());

            return;
        }
        SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)->update(['status' => 'superseded', 'updated_at' => now()]);
        $loan->fill(['status' => 'signed', 'schedule_version' => (int) $loan->schedule_version + 1, 'disbursed_on' => null, 'first_due_on' => null, 'maturity_on' => null,
            'disbursement_entry_id' => null, 'payout_id' => null, 'decision_note' => 'Mobile-money payout failed: '.($payout->last_error ?: 'refused')])->save();
        SaccoAudit::record('sacco.loan.payout_failed', $loan, (int) $loan->company_id, ['payout_id' => $payout->id], null);
    }

    /**
     * Cancel within the cooling-off: the member returns what they received and the cancellation fee, in cash, by bank
     * or from savings.
     *
     * @param  array<string, mixed>  $data  channel (cash|bank|savings), savings_account_id, uuid, value_date
     */
    public function cancel(Company $company, int $loanId, User $by, array $data, string $reason = 'Cancelled in the cooling-off'): SaccoLoan
    {
        SaccoAccess::authorize($by, 'sacco_teller');
        $cid = (int) $company->id;
        $uuid = strtolower((string) ($data['uuid'] ?? Str::uuid()));

        return DB::transaction(function () use ($cid, $loanId, $by, $data, $uuid, $reason) {
            SaccoTransactions::lockHead($cid);
            $loan = LoanBooks::lock($cid, $loanId);
            if ($loan->status === 'cancelled' && SaccoLoanRepayment::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->exists()) {
                return $loan; // replayed
            }
            if ($loan->status === 'signed') {
                $loan->update(['status' => 'cancelled', 'cancelled_on' => LoanBooks::today($cid)->toDateString(), 'decision_note' => mb_substr($reason, 0, 255)]);
                (new Guarantors())->releaseAll($loan, 'The loan was cancelled.', (int) $by->id);

                return $loan; // nothing was paid out
            }
            LoanBooks::assertStatus($loan, ['active'], 'cancelled');
            $on = Carbon::parse($data['value_date'] ?? LoanBooks::today($cid))->startOfDay();
            if ($loan->cooling_off_ends_on === null || $on->gt($loan->cooling_off_ends_on)) {
                throw BusinessRuleException::make('cooling_off_over', 'The cooling-off ended on '.($loan->cooling_off_ends_on?->toDateString() ?? 'signing').': repay the loan instead (no early-repayment fee).');
            }
            if (SaccoLoanRepayment::withoutGlobalScopes()->where('loan_id', $loan->id)->where('status', 'posted')->exists()) {
                throw BusinessRuleException::make('cooling_off_over', 'A repayment has been made: repay the rest instead.');
            }
            $terms = $loan->loanTerms();
            $currency = (string) $loan->currency;
            $principal = (int) $loan->approved_minor;
            $charges = Schedule::upfrontCharges($principal, $terms, $currency);
            $fee = Amount::of($principal, $currency)->times(bcdiv((string) $terms->cancellationFeeBp, '10000', 4))->minor;
            $money = fn (int $minor) => Amount::of($minor, $currency);
            $pays = $principal - $charges['fee'] - $charges['insurance'] + $fee;
            $channel = (string) ($data['channel'] ?? 'cash');
            $rules = new LoanPostingRules($cid, LoanBooks::settings($cid)->mode);
            $savings = $channel === 'savings' ? MemberFunds::payoutAccount($cid, (int) $loan->member_id, isset($data['savings_account_id']) ? (int) $data['savings_account_id'] : null) : null;
            if ($channel === 'savings' && $savings === null) {
                throw BusinessRuleException::make('account_not_found', 'The member has no open savings account.');
            }
            $draft = $rules->cancellation('txn:'.$uuid, $loan, $money($principal), $money($charges['fee']), $money($charges['insurance']), $money($fee), $money((int) $loan->interest_due_minor),
                $savings !== null ? MemberFunds::ref($savings) : null, $savings === null ? $rules->channelAccount($channel) : null);
            if ($savings !== null) {
                $draft->guard(function () use ($cid, $savings, $pays) {
                    if (SavingsAccounts::available($cid, (int) $savings->id, true) < $pays) {
                        throw BusinessRuleException::make('insufficient_available', 'The savings account does not hold enough to return the loan.');
                    }
                });
            }
            [$entryDate, $valueDate] = LoanBooks::dates($cid, $on);
            $stx = new SaccoTransactions();
            $tx = $stx->document($cid, ['uuid' => $uuid, 'kind' => 'loan_cancellation', 'channel' => $channel === 'savings' ? 'internal' : $channel, 'member_id' => (int) $loan->member_id,
                'savings_account_id' => $savings?->id, 'loan_id' => (int) $loan->id, 'amount_minor' => $pays, 'value_date' => $valueDate, 'narration' => 'Loan '.$loan->loan_no.' cancelled'],
                $by, ['entry_date' => $entryDate], 'posted');
            $tx = $stx->post($tx, $draft, (int) $by->id, ['entry_date' => $entryDate]);
            SaccoLoanRepayment::create(['company_id' => $cid, 'loan_id' => $loan->id, 'uuid' => $uuid, 'kind' => 'cancellation', 'channel' => $channel, 'source_savings_account_id' => $savings?->id,
                'amount_minor' => $pays, 'principal_minor' => $principal, 'value_date' => $valueDate, 'schedule_version' => (int) $loan->schedule_version,
                'journal_entry_id' => $tx->journal_entry_id, 'transaction_id' => $tx->id, 'received_by_id' => $by->id, 'narration' => $reason]);
            SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)->update(['status' => 'superseded', 'updated_at' => now()]);
            $loan->refresh()->fill(['status' => 'cancelled', 'cancelled_on' => $valueDate, 'decision_note' => mb_substr($reason, 0, 255)])->save();
            SaccoLoanChange::create(['company_id' => $cid, 'loan_id' => $loan->id, 'kind' => 'cancellation', 'amount_minor' => $pays, 'reason' => mb_substr($reason, 0, 255),
                'entry_id' => $tx->journal_entry_id, 'effective_on' => $valueDate, 'created_by_id' => $by->id, 'after' => ['cancellation_fee_minor' => $fee]]);
            (new Guarantors())->releaseAll($loan, 'The loan was cancelled.', (int) $by->id);
            Collateral::releaseAll($cid, (int) $loan->id, $valueDate);
            SaccoAudit::record('sacco.loan.cancelled', $loan, $cid, ['returned_minor' => $pays, 'fee_minor' => $fee], (int) $by->id);

            return $loan;
        });
    }

    private function tellDisbursed(SaccoLoan $loan, int $net, int $firstInstalment, string $firstDue): void
    {
        $cid = (int) $loan->company_id;
        LoanNotices::toMember($cid, (int) $loan->member_id, 'loan_disbursed', ['amount' => LoanBooks::fmt($cid, $net),
            'channel' => ['savings' => 'your savings', 'cash' => 'you in cash', 'bank' => 'your bank', 'momo' => 'your mobile money'][$loan->disbursement_channel] ?? 'you',
            'due' => LoanBooks::fmt($cid, $firstInstalment), 'date' => $firstDue, 'cooling' => $loan->loanTerms()->coolingOffWorkingDays]);
    }
}

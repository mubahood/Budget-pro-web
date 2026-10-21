<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanChange;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoLoanScheduleLine;
use App\Models\User;
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
 * Rescheduling and top-ups (04 §7 `reschedule`, `top_up`, P2.13; SI 108 reg. 6, 12; SI 51 Form 1, AK-40).
 *
 * - Reschedule: the principal still owed is spread over a new term from a new first due date, as a new schedule
 *   version; the old lines are kept, superseded. It needs the borrower's written consent (reg. 6: no variation
 *   without it) and the committee (a loans committee member, the chair or the board approves). Interest to the day is
 *   charged for the running period first, arrears of interest and penalty stay owed (never capitalised: no interest on
 *   interest), the loan keeps its class until six instalments are paid on time (Arrears), and its reschedule count
 *   is what Form 1's rescheduled-loans line reads.
 * - Top-up: a new loan that settles the old one with no early-settlement charge (reg. 12): it is applied for,
 *   appraised, decided, contracted and signed like any loan (fresh disclosure and guarantor consent); at payout the
 *   old loan's settlement (interest to the day, nothing more) is paid out of the new principal and only the rest
 *   reaches the member.
 */
final class Restructure
{
    public const APPROVER_POSITIONS = ['loans_committee', 'chairperson', 'vice_chair', 'board_member'];

    /**
     * @param  array<string, mixed>  $data  term, principal_grace_periods, first_due_on, reason, consent_signed_on, consent_file_id, consent_witness
     */
    public function requestReschedule(Company $company, int $loanId, User $maker, array $data, ?string $uuid = null): SaccoApproval
    {
        SaccoAccess::authorize($maker, 'sacco_loans');
        $cid = (int) $company->id;
        $loan = LoanBooks::find($cid, $loanId);
        LoanBooks::assertStatus($loan, ['active'], 'rescheduled');
        if (LoanBooks::strict($cid) && (blank($data['consent_signed_on'] ?? null) || (blank($data['consent_file_id'] ?? null) && blank($data['consent_witness'] ?? null)))) {
            throw BusinessRuleException::make('consent_required', 'Record the borrower\'s signed consent to the new terms first.');
        }
        if (blank($data['reason'] ?? null)) {
            throw BusinessRuleException::make('reason_required', 'Say why the loan is rescheduled.');
        }
        $data['consent_signed_on'] = $data['consent_signed_on'] ?? null;
        $term = (int) ($data['term'] ?? 0);
        $terms = $loan->loanTerms()->with($term, (int) ($data['principal_grace_periods'] ?? 0)); // validates the term and the grace

        return (new SaccoApprovals())->request($company, 'reschedule', ['loan_id' => (int) $loan->id, 'loan_no' => $loan->loan_no, 'member_id' => (int) $loan->member_id,
            'term' => $terms->term, 'principal_grace_periods' => $terms->principalGracePeriods, 'first_due_on' => $data['first_due_on'] ?? null, 'on' => $data['on'] ?? null,
            'reason' => mb_substr((string) $data['reason'], 0, 255), 'consent_signed_on' => $data['consent_signed_on'], 'consent_file_id' => $data['consent_file_id'] ?? null,
            'consent_witness' => $data['consent_witness'] ?? null, 'amount_minor' => (int) $loan->principal_outstanding_minor], $maker, $uuid ?? (string) Str::uuid());
    }

    /** @return array<string, mixed> */
    public function executeReschedule(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        $cid = (int) $approval->company_id;
        SaccoTransactions::lockHead($cid);
        $loan = LoanBooks::lock($cid, (int) $p['loan_id']);
        LoanBooks::assertStatus($loan, ['active'], 'rescheduled');
        $on = Carbon::parse($p['on'] ?? LoanBooks::today($cid))->startOfDay();
        $currency = (string) $loan->currency;
        $from = (int) $loan->schedule_version;
        // Interest to the day for the running period, so the new schedule starts clean.
        $toDay = Schedule::interestToDay((int) $loan->principal_outstanding_minor, $loan->loanTerms(), Repayments::periodStart($loan, $on), $on, $currency);
        if ($toDay > 0) {
            [$entryDate, $valueDate] = LoanBooks::dates($cid, $on);
            $rules = new LoanPostingRules($cid, LoanBooks::settings($cid)->mode);
            if ($loan->isNpl()) {
                DB::table('sacco_loans')->where('id', $loan->id)->update(['interest_in_suspense_minor' => DB::raw('interest_in_suspense_minor + '.$toDay)]);
            } else {
                (new PostingService())->post($rules->interestDue('int:'.$loan->id.':'.$from.':resched', $loan, Amount::of($toDay, $currency))->dated($entryDate, $valueDate)
                    ->source('sacco_loans', (int) $loan->id)->memo('Interest to the day before rescheduling, loan '.$loan->loan_no));
            }
            $loan->refresh();
        }
        $terms = $loan->loanTerms()->with((int) $p['term'], (int) $p['principal_grace_periods']);
        $first = isset($p['first_due_on']) ? Carbon::parse($p['first_due_on'])->startOfDay() : Schedule::firstDueAfter($on, $terms->frequency);
        if ($first->lte($on)) {
            throw BusinessRuleException::make('invalid_first_due', 'The first new instalment falls after the reschedule.');
        }
        $principal = (int) $loan->principal_outstanding_minor;
        if ($principal < 1) {
            throw BusinessRuleException::make('nothing_to_reschedule', 'No principal is owed: there is nothing to reschedule.');
        }
        $before = ['schedule_version' => $from, 'term' => $loan->loanTerms()->term, 'maturity_on' => $loan->maturity_on?->toDateString(), 'principal_minor' => $principal,
            'classification' => $loan->classification, 'days_past_due' => (int) $loan->days_past_due];
        SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $from)->where('status', '!=', 'paid')
            ->update(['status' => 'superseded', 'updated_at' => now()]);
        $to = $from + 1;
        $lines = Schedule::build($principal, $terms, $first, $currency);
        foreach ($lines as $l) {
            SaccoLoanScheduleLine::create(['company_id' => $cid, 'loan_id' => $loan->id, 'schedule_version' => $to, 'installment_no' => $l->no, 'due_on' => $l->dueOn,
                'opening_principal_minor' => $l->openingPrincipal, 'principal_due_minor' => $l->principal, 'interest_due_minor' => $l->interest]);
        }
        $loan->fill(['schedule_version' => $to, 'terms' => $terms->toArray(), 'maturity_on' => end($lines)->dueOn, 'reschedule_count' => (int) $loan->reschedule_count + 1,
            'good_instalments' => 0, 'arrears_on' => $on->toDateString()])->save();
        $change = SaccoLoanChange::create(['company_id' => $cid, 'loan_id' => $loan->id, 'kind' => 'reschedule', 'from_version' => $from, 'to_version' => $to, 'before' => $before,
            'after' => ['term' => $terms->term, 'first_due_on' => $first->toDateString(), 'maturity_on' => end($lines)->dueOn, 'instalment_minor' => $lines[min(count($lines) - 1, $terms->principalGracePeriods)]->instalment(),
                'interest_to_day_minor' => $toDay, 'consent_witness' => $p['consent_witness'] ?? null],
            'amount_minor' => $principal, 'reason' => $p['reason'], 'approval_id' => $approval->id, 'borrower_consented_at' => $p['consent_signed_on'] !== null ? Carbon::parse($p['consent_signed_on']) : null,
            'effective_on' => $on->toDateString(), 'created_by_id' => $checker->id]);
        SaccoAudit::record('sacco.loan.rescheduled', $loan, $cid, ['from' => $from, 'to' => $to, 'term' => $terms->term], (int) $checker->id);

        return ['loan_id' => (int) $loan->id, 'schedule_version' => $to, 'change_id' => (int) $change->id];
    }

    /**
     * Apply for a top-up: a new loan for $amountMinor that will settle the old one at payout. It goes through the usual
     * appraisal, decision, contract and signing.
     *
     * @param  array<string, mixed>  $data  applied_minor (the whole new principal), term, purpose, disbursement_channel
     */
    public function applyTopUp(Company $company, int $oldLoanId, array $data, User $by): SaccoLoan
    {
        SaccoAccess::authorize($by, 'sacco_loans');
        $cid = (int) $company->id;
        $old = LoanBooks::find($cid, $oldLoanId);
        LoanBooks::assertStatus($old, ['active'], 'topped up');
        $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($data['product_id'] ?? $old->product_id);
        if (! $product->allow_top_up) {
            throw BusinessRuleException::make('top_up_not_allowed', "{$product->name} cannot be topped up.");
        }
        $quote = (new Repayments())->quote($cid, (int) $old->id);
        if ((int) ($data['applied_minor'] ?? 0) <= (int) $quote['total_minor']) {
            throw BusinessRuleException::make('top_up_too_small', 'A top-up must be more than what settles the old loan ('.$quote['total_minor'].').', ['settlement_minor' => (int) $quote['total_minor']]);
        }
        $svc = new LoanApplications();
        $loan = $svc->create($company, ['member_id' => (int) $old->member_id, 'product_id' => (int) $product->id] + $data, $by);
        $loan->forceFill(['top_up_of_loan_id' => $old->id])->save();
        SaccoLoanChange::create(['company_id' => $cid, 'loan_id' => $old->id, 'kind' => 'top_up', 'before' => ['settlement_quote_minor' => (int) $quote['total_minor']],
            'after' => ['new_loan_id' => (int) $loan->id, 'applied_minor' => (int) $loan->applied_minor], 'effective_on' => LoanBooks::today($cid)->toDateString(), 'created_by_id' => $by->id]);

        return $svc->submit($company, (int) $loan->id, $by);
    }

    /**
     * At the new loan's payout (Disbursement::execute, same transaction): the old loan is settled out of the money just
     * paid out (from savings, or from the cash the teller would have handed over), interest to the day and no fee.
     *
     * @return int what the settlement took
     */
    public function settleOld(Company $company, SaccoLoan $new, ?\App\Models\SaccoSavingsAccount $savings, string $channel, string $on, User $by): int
    {
        $cid = (int) $company->id;
        $old = LoanBooks::lock($cid, (int) $new->top_up_of_loan_id);
        LoanBooks::assertStatus($old, ['active'], 'settled by a top-up');
        $quote = (new Repayments())->quote($cid, (int) $old->id, $on);
        $r = (new Repayments())->apply($company, $old, (int) $quote['total_minor'], $savings !== null ? 'internal' : $channel, $savings,
            ['uuid' => (string) Str::uuid(), 'value_date' => $on, 'narration' => 'Settled by top-up '.$new->loan_no], $by, ['settle' => true], 'top_up_settlement');
        $old->refresh()->fill(['topped_up_by_loan_id' => $new->id])->save();
        SaccoAudit::record('sacco.loan.topped_up', $old, $cid, ['new_loan_id' => $new->id, 'settled_minor' => (int) $quote['total_minor']], (int) $by->id);

        return (int) $r['repayment']->amount_minor;
    }
}

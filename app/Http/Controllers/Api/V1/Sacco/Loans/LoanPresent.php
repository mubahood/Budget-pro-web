<?php

namespace App\Http\Controllers\Api\V1\Sacco\Loans;

use App\Models\SaccoLoan;
use App\Models\SaccoLoanCollateral;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoLoanRepayment;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoMember;
use App\Services\Sacco\Loans\Guarantors;

/** The API's shapes for loans (04 §13): money as `*_minor` integers with `currency`, dates Y-m-d; a guarantor sees only the first name of the borrower. */
final class LoanPresent
{
    /** @return array<string, mixed> */
    public static function product(SaccoLoanProduct $p): array
    {
        return $p->toArray();
    }

    /** @return array<string, mixed> */
    public static function loan(SaccoLoan $l, bool $full = false): array
    {
        $member = SaccoMember::withoutGlobalScopes()->find($l->member_id);
        $out = ['id' => (int) $l->id, 'uuid' => $l->uuid, 'loan_no' => $l->loan_no, 'status' => $l->status, 'source' => $l->source, 'member_id' => (int) $l->member_id,
            'member_no' => $member?->member_no, 'member_name' => $member?->full_name, 'product_id' => (int) $l->product_id, 'officer_id' => $l->officer_id,
            'applied_minor' => (int) $l->applied_minor, 'approved_minor' => $l->approved_minor, 'currency' => $l->currency, 'term' => $l->loanTerms()->term,
            'purpose' => $l->purpose, 'purpose_category' => $l->purpose_category, 'is_insider' => (bool) $l->is_insider, 'disbursement_channel' => $l->disbursement_channel,
            'principal_outstanding_minor' => (int) $l->principal_outstanding_minor, 'interest_due_minor' => (int) $l->interest_due_minor, 'penalty_due_minor' => (int) $l->penalty_due_minor,
            'fees_due_minor' => (int) $l->fees_due_minor, 'interest_in_suspense_minor' => (int) $l->interest_in_suspense_minor, 'penalty_in_suspense_minor' => (int) $l->penalty_in_suspense_minor,
            'owed_minor' => $l->owedMinor(), 'arrears_principal_minor' => (int) $l->arrears_principal_minor, 'arrears_interest_minor' => (int) $l->arrears_interest_minor,
            'days_past_due' => (int) $l->days_past_due, 'instalments_in_arrears' => (int) $l->instalments_in_arrears, 'classification' => $l->classification,
            'npl_since' => $l->npl_since?->toDateString(), 'reschedule_count' => (int) $l->reschedule_count, 'schedule_version' => (int) $l->schedule_version,
            'top_up_of_loan_id' => $l->top_up_of_loan_id, 'topped_up_by_loan_id' => $l->topped_up_by_loan_id, 'submitted_at' => $l->submitted_at?->toIso8601String(),
            'approved_at' => $l->approved_at?->toIso8601String(), 'signed_on' => $l->signed_on?->toDateString(), 'cooling_off_ends_on' => $l->cooling_off_ends_on?->toDateString(),
            'disbursed_on' => $l->disbursed_on?->toDateString(), 'first_due_on' => $l->first_due_on?->toDateString(), 'maturity_on' => $l->maturity_on?->toDateString(),
            'closed_on' => $l->closed_on?->toDateString(), 'written_off_on' => $l->written_off_on?->toDateString(), 'written_off_minor' => (int) $l->written_off_minor,
            'recovered_minor' => (int) $l->recovered_minor, 'decision_approval_id' => $l->decision_approval_id, 'disbursement_approval_id' => $l->disbursement_approval_id];
        if ($full) {
            $out['terms'] = $l->terms;
            $out['appraisal'] = $l->appraisal;
            $out['decision_note'] = $l->decision_note;
            $out['guarantors'] = $l->guarantors()->withoutGlobalScopes()->orderBy('id')->get()->map(fn ($g) => self::guarantor($g))->all();
            $out['collateral'] = $l->collateral()->withoutGlobalScopes()->orderBy('id')->get()->map(fn ($c) => self::collateral($c))->all();
            $out['documents'] = $l->documents()->withoutGlobalScopes()->orderBy('id')->get(['id', 'type', 'sha256', 'schedule_version', 'generated_at', 'signed_by_member_at', 'witness_name'])->toArray();
            $out['votes'] = $l->votes()->withoutGlobalScopes()->orderBy('id')->get(['round', 'officer_id', 'user_id', 'vote', 'recused', 'note', 'created_at'])->toArray();
            $out['changes'] = $l->changes()->withoutGlobalScopes()->orderBy('id')->get()->toArray();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function line(SaccoLoanScheduleLine $l): array
    {
        return ['installment_no' => (int) $l->installment_no, 'schedule_version' => (int) $l->schedule_version, 'due_on' => $l->due_on->toDateString(),
            'opening_principal_minor' => (int) $l->opening_principal_minor, 'principal_due_minor' => (int) $l->principal_due_minor, 'interest_due_minor' => (int) $l->interest_due_minor,
            'interest_charged_minor' => $l->interest_charged_minor, 'interest_suspended' => (bool) $l->interest_suspended, 'principal_paid_minor' => (int) $l->principal_paid_minor,
            'interest_paid_minor' => (int) $l->interest_paid_minor, 'status' => $l->status, 'paid_on' => $l->paid_on?->toDateString()];
    }

    /** @return array<string, mixed> */
    public static function guarantor(SaccoLoanGuarantor $g): array
    {
        $m = SaccoMember::withoutGlobalScopes()->find($g->guarantor_member_id);

        return ['id' => (int) $g->id, 'loan_id' => (int) $g->loan_id, 'guarantor_member_id' => (int) $g->guarantor_member_id, 'guarantor_name' => $m?->full_name,
            'guarantor_member_no' => $m?->member_no, 'guaranteed_minor' => (int) $g->guaranteed_minor, 'consent_status' => $g->consent_status, 'consent_channel' => $g->consent_channel,
            'notice_sent_at' => $g->notice_sent_at?->toIso8601String(), 'consented_at' => $g->consented_at?->toIso8601String(), 'held_minor' => (int) $g->held_minor,
            'hold_savings_account_id' => $g->hold_savings_account_id, 'recovered_minor' => (int) $g->recovered_minor, 'released_at' => $g->released_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public static function collateral(SaccoLoanCollateral $c): array
    {
        return $c->toArray();
    }

    /** @return array<string, mixed> */
    public static function repayment(SaccoLoanRepayment $r): array
    {
        return $r->toArray();
    }

    /** What a guarantor sees of a request in their own sign-in. @return array<string, mixed> */
    public static function guaranteeRequest(SaccoLoanGuarantor $g): array
    {
        $loan = SaccoLoan::withoutGlobalScopes()->find($g->loan_id);
        $borrower = $loan === null ? null : SaccoMember::withoutGlobalScopes()->find($loan->member_id);

        return ['id' => (int) $g->id, 'borrower' => $borrower?->first_name, 'guaranteed_minor' => (int) $g->guaranteed_minor, 'loan_minor' => (int) ($loan?->approved_minor ?? $loan?->applied_minor),
            'currency' => $loan?->currency, 'status' => $g->consent_status, 'held_minor' => (int) $g->held_minor, 'loan_status' => $loan?->status,
            'exposure' => Guarantors::exposure((int) $g->company_id, (int) $g->guarantor_member_id)];
    }
}

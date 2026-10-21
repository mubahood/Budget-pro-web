<?php

namespace App\Services\Sacco\Loans\Executors;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\SaccoLoan;
use App\Models\SaccoOfficer;
use App\Models\User;
use App\Services\Sacco\Approvals\BaseExecutor;
use App\Services\Sacco\Approvals\ChecksChecker;
use App\Services\Sacco\Approvals\ClosesSubject;
use App\Services\Sacco\Loans\Committee;
use App\Services\Sacco\Loans\Eligibility;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Loans\LoanNotices;
use App\Services\Sacco\Loans\LoanProducts;
use App\Services\Sacco\Loans\Schedule;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;

/**
 * `loan_decision` (04 §7): approve a loan, asked by its appraiser. The checker depends on the route the request
 * carries (Committee): an officer within their delegated limit (sacco_approve and a position whose approval limit
 * covers the amount), or the committee's or board's carrying vote, cast by Committee itself. Approving checks the
 * single-borrower limit (Eligibility `approve`); a rejection, cancellation or expiry moves the loan on.
 */
final class LoanDecisionExecutor extends BaseExecutor implements ChecksChecker, ClosesSubject
{
    public function action(): string
    {
        return 'loan_decision';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_loans';
    }

    /** Every SACCO login has sacco_view; the real rule is per route (assertCanCheck). */
    public function checkerPermission(): string
    {
        return 'sacco_view';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $loanId = $this->int($payload, 'loan_id');
        $route = (string) ($payload['route'] ?? '');
        if (! in_array($route, array_keys(Committee::ROUNDS), true)) {
            throw BusinessRuleException::make('invalid_request', 'A loan decision is delegated, by the committee, or on appeal.');
        }
        $amount = $this->int($payload, 'amount_minor');

        return ['payload' => ['loan_id' => $loanId, 'loan_no' => $payload['loan_no'] ?? null, 'route' => $route, 'amount_minor' => $amount,
            'term' => $this->int($payload, 'term'), 'member_id' => $this->int($payload, 'member_id')],
            'subject_type' => 'sacco_loans', 'subject_id' => $loanId, 'amount_minor' => $amount];
    }

    public function assertCanCheck(SaccoApproval $approval, User $checker, string $decision): void
    {
        $route = (string) ($approval->payload['route'] ?? '');
        if ($route !== 'delegated') {
            if (Committee::casting() !== (int) $approval->id) {
                throw BusinessRuleException::make('committee_vote_only', 'The committee decides this loan by vote.');
            }

            return;
        }
        SaccoAccess::authorize($checker, 'sacco_approve');
        $cid = (int) $approval->company_id;
        $loan = LoanBooks::find($cid, (int) $approval->payload['loan_id']);
        if ($loan->is_insider) {
            throw BusinessRuleException::make('insider_to_committee', 'An insider\'s loan goes to the committee.');
        }
        $limit = (int) SaccoOfficer::withoutGlobalScopes()->where('company_id', $cid)->where('user_id', $checker->id)->activeOn(LoanBooks::today($cid))
            ->max('approval_limit_minor');
        if ($decision === 'approve' && $limit < (int) $approval->payload['amount_minor']) {
            throw BusinessRuleException::make('delegated_limit', 'This loan is above your approval limit: send it to the committee.', ['limit_minor' => $limit]);
        }
        if ($limit === 0) {
            throw BusinessRuleException::make('delegated_limit', 'Your position has no loan approval limit.', ['limit_minor' => 0]);
        }
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        $cid = (int) $approval->company_id;
        $loan = LoanBooks::lock($cid, (int) $p['loan_id']);
        LoanBooks::assertStatus($loan, ['appraised', 'in_committee', 'appealed'], 'approved');
        $loan->approved_minor = (int) $p['amount_minor'];
        (new Eligibility())->assert($loan, 'approve');
        $loan->fill(['status' => 'approved', 'approved_at' => now(), 'decision_approval_id' => $approval->id, 'decision_note' => $approval->decision_note])->save();
        SaccoAudit::record('sacco.loan.approved', $loan, $cid, ['route' => $p['route'], 'amount_minor' => (int) $p['amount_minor']], (int) $checker->id);
        $facts = LoanProducts::keyFacts((int) $p['amount_minor'], $loan->loanTerms(), LoanBooks::today($cid), Schedule::firstDueAfter(LoanBooks::today($cid), $loan->loanTerms()->frequency), (string) $loan->currency);
        LoanNotices::toMember($cid, (int) $loan->member_id, 'loan_approved', ['loan' => (string) $loan->loan_no, 'amount' => LoanBooks::fmt($cid, (int) $p['amount_minor']),
            'days' => 7, 'cost' => LoanBooks::fmt($cid, (int) $facts['total_cost_of_credit_minor'])]);

        return ['loan_id' => (int) $loan->id, 'status' => 'approved'];
    }

    public function closed(SaccoApproval $approval, string $status): void
    {
        $cid = (int) $approval->company_id;
        $loan = SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($approval->payload['loan_id'] ?? 0);
        if ($loan === null || ! in_array($loan->status, ['appraised', 'in_committee', 'appealed'], true)) {
            return;
        }
        $appeal = ($approval->payload['route'] ?? null) === 'appeal';
        if ($status === 'rejected' || $appeal) {
            $loan->fill(['status' => 'rejected', 'rejected_at' => now(), 'decision_note' => $approval->decision_note])->save();
            (new \App\Services\Sacco\Loans\Guarantors())->releaseAll($loan, 'The loan was not approved.');
            LoanNotices::toMember($cid, (int) $loan->member_id, 'loan_rejected', ['loan' => (string) $loan->loan_no]);
        } else {
            $loan->fill(['status' => 'appraised'])->save(); // cancelled or expired: it can be sent again
        }
    }
}

<?php

namespace App\Services\Sacco\Loans\Executors;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Approvals\BaseExecutor;
use App\Services\Sacco\Approvals\ChecksChecker;
use App\Services\Sacco\Loans\Disbursement;
use App\Services\Sacco\Loans\LoanBooks;

/** `loan_disbursement` (04 §7): sacco_disburse asks, sacco_approve checks, and the checker is never the loan's appraiser. */
final class LoanDisbursementExecutor extends BaseExecutor implements ChecksChecker
{
    public function action(): string
    {
        return 'loan_disbursement';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_disburse';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $loanId = $this->int($payload, 'loan_id');
        $amount = $this->int($payload, 'amount_minor');
        if (! in_array($payload['channel'] ?? null, ['savings', 'cash', 'bank', 'momo'], true)) {
            throw BusinessRuleException::make('invalid_request', 'Pay out to savings, in cash, by bank or by mobile money.');
        }

        return ['payload' => $payload, 'subject_type' => 'sacco_loans', 'subject_id' => $loanId, 'amount_minor' => $amount];
    }

    public function assertCanCheck(SaccoApproval $approval, User $checker, string $decision): void
    {
        $loan = LoanBooks::find((int) $approval->company_id, (int) $approval->payload['loan_id']);
        if ($decision === 'approve' && $loan->appraised_by_id !== null && (int) $loan->appraised_by_id === (int) $checker->id) {
            throw BusinessRuleException::make('self_approval', 'You appraised this loan: another officer approves its payout.');
        }
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new Disbursement())->execute($approval, $checker);
    }
}

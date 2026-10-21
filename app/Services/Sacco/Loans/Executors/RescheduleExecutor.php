<?php

namespace App\Services\Sacco\Loans\Executors;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Approvals\BaseExecutor;
use App\Services\Sacco\Loans\Restructure;

/** `reschedule` (04 §7): sacco_loans asks with the borrower's written consent; a committee member, the chair or the board approves. */
final class RescheduleExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'reschedule';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_loans';
    }

    public function checkerPermission(): string
    {
        return 'sacco_view';
    }

    public function checkerPositions(): array
    {
        return Restructure::APPROVER_POSITIONS;
    }

    public function prepare(int $companyId, array $payload): array
    {
        return ['payload' => $payload, 'subject_type' => 'sacco_loans', 'subject_id' => $this->int($payload, 'loan_id'), 'amount_minor' => $this->int($payload, 'amount_minor')];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new Restructure())->executeReschedule($approval, $checker);
    }
}

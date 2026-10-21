<?php

namespace App\Services\Sacco\Loans\Executors;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Approvals\BaseExecutor;
use App\Services\Sacco\Loans\GuarantorRecovery;

/** `guarantor_recovery` (AK-88): sacco_loans asks, sacco_approve checks; the guarantor's held savings pay the loan. */
final class GuarantorRecoveryExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'guarantor_recovery';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_loans';
    }

    public function prepare(int $companyId, array $payload): array
    {
        return ['payload' => $payload, 'subject_type' => 'sacco_loan_guarantors', 'subject_id' => $this->int($payload, 'guarantor_id'), 'amount_minor' => $this->int($payload, 'amount_minor')];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new GuarantorRecovery())->execute($approval, $checker);
    }
}

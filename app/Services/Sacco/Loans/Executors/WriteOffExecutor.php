<?php

namespace App\Services\Sacco\Loans\Executors;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Approvals\BaseExecutor;
use App\Services\Sacco\Loans\WriteOffs;

/** `write_off` (04 §7): sacco_loans asks; two checkers with sacco_approve, each holding the chair's, vice chair's or treasurer's office. */
final class WriteOffExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'write_off';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_loans';
    }

    public function checkerPositions(): array
    {
        return ['chairperson', 'vice_chair', 'treasurer'];
    }

    public function requiredApprovals(array $payload): int
    {
        return 2;
    }

    public function prepare(int $companyId, array $payload): array
    {
        $loanId = $this->int($payload, 'loan_id');

        return ['payload' => $payload, 'subject_type' => 'sacco_loans', 'subject_id' => $loanId, 'amount_minor' => $this->int($payload, 'amount_minor')];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new WriteOffs())->execute($approval, $checker);
    }
}

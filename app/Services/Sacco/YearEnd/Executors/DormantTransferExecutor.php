<?php

namespace App\Services\Sacco\YearEnd\Executors;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Approvals\BaseExecutor;
use App\Services\Sacco\YearEnd\Dormancy;

/** `dormant_transfer` (P4.10): sacco_accounts asks for an unclaimed dormant balance to go to the regulator; sacco_approve checks. */
final class DormantTransferExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'dormant_transfer';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_accounts';
    }

    public function prepare(int $companyId, array $payload): array
    {
        return ['payload' => $payload, 'subject_type' => 'sacco_savings_accounts', 'subject_id' => $this->int($payload, 'savings_account_id'),
            'amount_minor' => $this->int($payload, 'amount_minor')];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new Dormancy())->executeTransfer($approval, $checker);
    }
}

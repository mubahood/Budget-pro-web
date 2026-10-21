<?php

namespace App\Services\Sacco\YearEnd\Executors;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Approvals\BaseExecutor;
use App\Services\Sacco\Approvals\ClosesSubject;
use App\Services\Sacco\YearEnd\ShareTransferFund;

/** `stf_buyback` (P4.9): sacco_members asks for a leaving member's shares to be bought by the Share Transfer Fund; sacco_approve checks. */
final class StfBuybackExecutor extends BaseExecutor implements ClosesSubject
{
    public function action(): string
    {
        return 'stf_buyback';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_members';
    }

    public function prepare(int $companyId, array $payload): array
    {
        return ['payload' => $payload, 'subject_type' => 'sacco_stf_buybacks', 'subject_id' => $this->int($payload, 'buyback_id'), 'amount_minor' => $this->int($payload, 'amount_minor')];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new ShareTransferFund())->execute($approval, $checker);
    }

    public function closed(SaccoApproval $approval, string $status): void
    {
        ShareTransferFund::closed($approval, $status);
    }
}

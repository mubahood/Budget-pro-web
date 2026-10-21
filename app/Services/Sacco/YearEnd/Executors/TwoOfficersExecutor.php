<?php

namespace App\Services\Sacco\YearEnd\Executors;

use App\Services\Sacco\Approvals\BaseExecutor;

/**
 * The year-end's money decisions (04 §7 `appropriation`, `dividend_run`; the year-end close with them): an officer with
 * sacco_accounts asks, and two officers with sacco_approve, each holding the chair's, vice chair's or treasurer's
 * office, approve (neither the maker).
 */
abstract class TwoOfficersExecutor extends BaseExecutor
{
    public function makerPermission(): ?string
    {
        return 'sacco_accounts';
    }

    public function checkerPositions(): array
    {
        return ['chairperson', 'vice_chair', 'treasurer'];
    }

    public function requiredApprovals(array $payload): int
    {
        return 2;
    }
}

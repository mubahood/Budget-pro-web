<?php

namespace App\Services\Sacco\Approvals;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Ledger\OpeningBalances;

/**
 * `opening_balances` (04 §13.2, P1.13): a members or ledger opening-balance file, checked and frozen row by row when
 * asked (maker sacco_accounts) and posted against 3900 when a second officer approves (sacco_approve).
 */
final class OpeningBalancesExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'opening_balances';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_accounts';
    }

    public function prepare(int $companyId, array $payload): array
    {
        return (new OpeningBalances())->prepare($companyId, $payload);
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $r = (new OpeningBalances())->post($approval);

        return ['entry_id' => $r['first_entry_id'], 'entries' => $r['entries']];
    }
}

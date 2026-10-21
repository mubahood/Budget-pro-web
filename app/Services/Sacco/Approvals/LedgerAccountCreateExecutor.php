<?php

namespace App\Services\Sacco\Approvals;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;

/** `ledger_account_create` (04 §7): a SACCO's own account, added to its chart once a second officer approves. */
final class LedgerAccountCreateExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'ledger_account_create';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_accounts';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $data = array_intersect_key($payload, array_flip(['code', 'name', 'type', 'normal_side', 'parent_id', 'umra_line']));
        $v = validator($data, ChartOfAccounts::accountRules($companyId));
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_account', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }

        return ['payload' => array_map(fn ($x) => is_string($x) ? trim($x) : $x, $v->validated()), 'subject_type' => 'sacco_ledger_accounts', 'subject_id' => null, 'amount_minor' => null];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $account = (new ChartOfAccounts())->addAccount((int) $approval->company_id, $approval->payload, $approval->requested_by_id === null ? null : (int) $approval->requested_by_id);

        return ['ledger_account_id' => (int) $account->id, 'code' => $account->code];
    }
}

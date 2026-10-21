<?php

namespace App\Services\Sacco\Approvals;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\SaccoPeriod;
use App\Models\User;
use App\Services\Sacco\Ledger\PeriodService;

/** `period_close` (04 §7): an accountant asks, a second officer approves, PeriodService closes the month. */
final class PeriodCloseExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'period_close';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_accounts';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $period = SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->find($this->int($payload, 'period_id'))
            ?? throw BusinessRuleException::make('period_not_found', 'That month is not in this SACCO\'s books.');
        if (! $period->isOpen()) {
            throw BusinessRuleException::make('period_not_open', $period->label().' is already closed.');
        }

        return ['payload' => ['period_id' => (int) $period->id, 'month' => $period->starts_on->format('Y-m')], 'subject_type' => 'sacco_periods', 'subject_id' => (int) $period->id, 'amount_minor' => null];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $period = (new PeriodService())->close((int) $approval->company_id, (int) $approval->payload['period_id'], (int) $checker->id, (int) $approval->id);

        return ['period_id' => (int) $period->id, 'snapshot_no' => $period->snapshot_no, 'close_hash' => $period->close_hash];
    }
}

<?php

namespace App\Services\Sacco\Approvals;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\SaccoPeriod;
use App\Models\User;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\SaccoAccess;

/** `period_reopen` (04 §7, AK-53): the latest closed month, with a reason; the checker holds the chair's or the auditor's office. */
final class PeriodReopenExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'period_reopen';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_accounts';
    }

    public function checkerPositions(): array
    {
        return SaccoAccess::REOPEN_POSITIONS;
    }

    public function prepare(int $companyId, array $payload): array
    {
        $period = SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->find($this->int($payload, 'period_id'))
            ?? throw BusinessRuleException::make('period_not_found', 'That month is not in this SACCO\'s books.');
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why the month is reopened: it is shown on the next closing report.');
        }
        if ($period->status !== 'closed') {
            throw BusinessRuleException::make('period_not_closed', $period->label().' is not closed.');
        }

        return ['payload' => ['period_id' => (int) $period->id, 'month' => $period->starts_on->format('Y-m'), 'reason' => mb_substr($reason, 0, 255)],
            'subject_type' => 'sacco_periods', 'subject_id' => (int) $period->id, 'amount_minor' => null];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        $period = (new PeriodService())->reopen((int) $approval->company_id, (int) $p['period_id'], (int) $checker->id, (string) $p['reason'], (int) $approval->id);

        return ['period_id' => (int) $period->id, 'reopen_count' => $period->reopen_count];
    }
}

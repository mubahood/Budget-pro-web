<?php

namespace App\Services\Sacco\Approvals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoSetting;

/** What most executors share: one checker with sacco_approve, no office needed, the company and mode of a request. */
abstract class BaseExecutor implements ApprovalExecutor
{
    public function checkerPermission(): string
    {
        return 'sacco_approve';
    }

    public function checkerPositions(): array
    {
        return [];
    }

    public function requiredApprovals(array $payload): int
    {
        return 1;
    }

    protected function company(SaccoApproval $a): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($a->company_id);
    }

    protected function mode(int $companyId): string
    {
        return SaccoSetting::for($companyId)?->mode ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
    }

    /** @param  array<string, mixed>  $payload */
    protected function int(array $payload, string $key): int
    {
        $v = $payload[$key] ?? null;
        if (! is_int($v) && ! (is_string($v) && ctype_digit($v))) {
            throw BusinessRuleException::make('invalid_request', "The request needs {$key} as a whole number.");
        }

        return (int) $v;
    }
}

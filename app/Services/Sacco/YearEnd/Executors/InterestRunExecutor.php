<?php

namespace App\Services\Sacco\YearEnd\Executors;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Approvals\BaseExecutor;
use App\Services\Sacco\YearEnd\SavingsInterest;

/**
 * `interest_run` (04 §13.2 P4): an officer with sacco_accounts asks for a month's savings interest to be worked out and
 * posted now (the monthly job does the same on its own); a second officer approves. Executing runs the month once:
 * a month already run is returned as it was.
 */
final class InterestRunExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'interest_run';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_accounts';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $month = (string) ($payload['month'] ?? '');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw BusinessRuleException::make('invalid_month', 'Name the month as YYYY-MM.');
        }
        $preview = [];
        $total = 0;
        foreach ((new SavingsInterest())->products($companyId) as $p) {
            $pv = (new SavingsInterest())->preview($companyId, (int) $p->id, $month);
            $preview[] = ['product_id' => (int) $p->id, 'name' => (string) $p->name, 'rate_bp' => $pv['rate_bp'], 'basis' => $pv['basis'], 'total_minor' => $pv['total_minor']];
            $total += $pv['total_minor'];
        }
        if ($preview === []) {
            throw BusinessRuleException::make('no_interest_products', 'No savings product earns interest: set a rate and a basis on one first.');
        }

        return ['payload' => ['month' => $month, 'products' => $preview], 'subject_type' => null, 'subject_id' => null, 'amount_minor' => $total];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new SavingsInterest())->run((int) $approval->company_id, (string) $approval->payload['month'], (int) $checker->id, (int) $approval->id);
    }
}

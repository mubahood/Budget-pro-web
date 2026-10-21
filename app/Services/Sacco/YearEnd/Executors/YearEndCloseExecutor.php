<?php

namespace App\Services\Sacco\YearEnd\Executors;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\YearEnd\Closing;

/** `year_end_close` (P4.7): the year's income and expenses to 3410, once two officers approve. */
final class YearEndCloseExecutor extends TwoOfficersExecutor
{
    public function action(): string
    {
        return 'year_end_close';
    }

    public function prepare(int $companyId, array $payload): array
    {
        return ['payload' => $payload, 'subject_type' => 'sacco_fiscal_years', 'subject_id' => (int) (new Closing())->fiscalYear($companyId, $this->int($payload, 'year'))->id,
            'amount_minor' => abs((int) ($payload['surplus_minor'] ?? 0))];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new Closing())->execute($approval, $checker);
    }
}

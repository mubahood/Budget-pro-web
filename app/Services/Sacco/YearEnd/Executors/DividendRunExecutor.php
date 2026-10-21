<?php

namespace App\Services\Sacco\YearEnd\Executors;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Approvals\ClosesSubject;
use App\Services\Sacco\YearEnd\Dividends;

/** `dividend_run` (P4.8, 04 §7): the declared dividend paid to the members, once two officers approve. */
final class DividendRunExecutor extends TwoOfficersExecutor implements ClosesSubject
{
    public function action(): string
    {
        return 'dividend_run';
    }

    public function prepare(int $companyId, array $payload): array
    {
        return ['payload' => $payload, 'subject_type' => 'sacco_dividend_runs', 'subject_id' => $this->int($payload, 'run_id'), 'amount_minor' => $this->int($payload, 'pool_minor')];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new Dividends())->execute($approval, $checker);
    }

    public function closed(SaccoApproval $approval, string $status): void
    {
        Dividends::closed($approval, $status);
    }
}

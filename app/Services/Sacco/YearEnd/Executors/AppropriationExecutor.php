<?php

namespace App\Services\Sacco\YearEnd\Executors;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Approvals\ClosesSubject;
use App\Services\Sacco\YearEnd\Appropriation;

/** `appropriation` (P4.7, 04 §7): the AGM's waterfall of a closed year's surplus, once two officers approve. */
final class AppropriationExecutor extends TwoOfficersExecutor implements ClosesSubject
{
    public function action(): string
    {
        return 'appropriation';
    }

    public function prepare(int $companyId, array $payload): array
    {
        return ['payload' => $payload, 'subject_type' => 'sacco_appropriations', 'subject_id' => $this->int($payload, 'appropriation_id'),
            'amount_minor' => max(0, (int) ($payload['surplus_minor'] ?? 0))];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new Appropriation())->execute($approval, $checker);
    }

    public function closed(SaccoApproval $approval, string $status): void
    {
        Appropriation::closed($approval, $status);
    }
}

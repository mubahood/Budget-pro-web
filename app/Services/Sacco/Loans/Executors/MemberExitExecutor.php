<?php

namespace App\Services\Sacco\Loans\Executors;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Approvals\BaseExecutor;
use App\Services\Sacco\Approvals\ClosesSubject;
use App\Services\Sacco\Members\MemberExit;

/** `member_exit` (04 §7): sacco_members asks, sacco_approve checks; the frozen settlement executes as journals. */
final class MemberExitExecutor extends BaseExecutor implements ClosesSubject
{
    public function action(): string
    {
        return 'member_exit';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_members';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $total = (int) ($payload['settlement']['shares']['amount_minor'] ?? 0) + array_sum(array_map(fn ($r) => (int) $r['amount_minor'], (array) ($payload['settlement']['savings'] ?? [])));

        return ['payload' => $payload, 'subject_type' => 'sacco_members', 'subject_id' => $this->int($payload, 'member_id'), 'amount_minor' => $total];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        return (new MemberExit())->execute($approval, $checker);
    }

    public function closed(SaccoApproval $approval, string $status): void
    {
        MemberExit::closed($approval, $status);
    }
}

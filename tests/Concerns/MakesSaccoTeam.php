<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Services\Team\TeamService;

/**
 * SACCO staff for the kernel's tests: one login per company role, made the way the Team screen makes them
 * (TeamService::createMember), so each signs in on the web and the API like any other team member.
 */
trait MakesSaccoTeam
{
    /** The eight sacco_* roles (config/permissions.php). */
    protected const SACCO_ROLES = ['sacco_manager', 'sacco_chair', 'sacco_treasurer', 'sacco_secretary', 'sacco_loans_officer', 'sacco_committee', 'sacco_auditor', 'sacco_teller'];

    protected function saccoStaff(Company $company, string $role, ?string $email = null): User
    {
        $owner = User::withoutGlobalScopes()->findOrFail($company->owner_id);

        return app(TeamService::class)->createMember($company, $owner, ucfirst(str_replace(['sacco_', '_'], ['', ' '], $role)).' '.uniqid(),
            $email ?? $role.'_'.uniqid().'@example.com', null, 'Secret-pass-123', $role);
    }
}

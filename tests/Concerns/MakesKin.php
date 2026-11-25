<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Team\TeamService;
use Database\Seeders\FamilyRootsPlanSeeder;

/**
 * A Family Roots account for the kernel's tests: registered with the kin module as the phone does (app=kin), on the Clan
 * plan, and officers by role. Every name is made up.
 */
trait MakesKin
{
    /** @return array{0: Company, 1: User, 2: string} the family's account, its head (owner), and the head's token */
    protected function kinAccount(array $overrides = []): array
    {
        if (! Plan::where('slug', 'family-roots-clan')->exists()) {
            $this->seed(FamilyRootsPlanSeeder::class);
        }
        $t = $this->registerTenant($overrides + ['modules' => ['kin'], 'currency' => 'UGX', 'company_name' => 'The Sample Family']);
        $company = Company::withoutGlobalScopes()->findOrFail($t['company_id']);
        if (($plan = Plan::where('slug', 'family-roots-clan')->value('id')) !== null) {
            Subscription::where('company_id', $company->id)->update(['plan_id' => $plan]);
        }

        return [$company->fresh(), User::withoutGlobalScopes()->findOrFail($t['user_id']), (string) $t['token']];
    }

    /** An officer (kin_secretary, kin_treasurer, kin_branch_coordinator) or a member (kin_member) of the family's team. */
    protected function kinStaff(Company $company, string $role): User
    {
        $owner = User::withoutGlobalScopes()->findOrFail($company->owner_id);

        return app(TeamService::class)->createMember($company, $owner, ucfirst(str_replace(['kin_', '_'], ['', ' '], $role)).' '.uniqid(),
            $role.'_'.uniqid().'@example.com', null, 'Secret-pass-123', $role);
    }
}

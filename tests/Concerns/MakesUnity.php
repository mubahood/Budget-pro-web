<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Team\TeamService;
use Database\Seeders\UnityDeskPlanSeeder;

/**
 * A UnityDesk account for the kernel's tests: registered with the unity module as the phone does (app=unitydesk), on the
 * Organisation plan, and officers by role. Every name is made up.
 */
trait MakesUnity
{
    /** @return array{0: Company, 1: User, 2: string} the association's account, its chair (owner), and the chair's token */
    protected function unityAccount(array $overrides = []): array
    {
        if (! Plan::where('slug', 'unitydesk-organisation')->exists()) {
            $this->seed(UnityDeskPlanSeeder::class);
        }
        $t = $this->registerTenant($overrides + ['modules' => ['unity'], 'currency' => 'UGX', 'company_name' => 'Sample Association']);
        $company = Company::withoutGlobalScopes()->findOrFail($t['company_id']);
        if (($plan = Plan::where('slug', 'unitydesk-organisation')->value('id')) !== null) {
            Subscription::where('company_id', $company->id)->update(['plan_id' => $plan]);
        }

        return [$company->fresh(), User::withoutGlobalScopes()->findOrFail($t['user_id']), (string) $t['token']];
    }

    /** An officer (unity_secretary, unity_treasurer, unity_chapter_lead) or a member (unity_member) of the association's team. */
    protected function unityStaff(Company $company, string $role, ?string $email = null): User
    {
        $owner = User::withoutGlobalScopes()->findOrFail($company->owner_id);

        return app(TeamService::class)->createMember($company, $owner, ucfirst(str_replace(['unity_', '_'], ['', ' '], $role)).' '.uniqid(),
            $email ?? $role.'_'.uniqid().'@example.com', null, 'Secret-pass-123', $role);
    }
}

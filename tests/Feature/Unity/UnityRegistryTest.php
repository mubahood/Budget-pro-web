<?php

namespace Tests\Feature\Unity;

use App\Http\Middleware\EnsureApiModule;
use App\Models\Plan;
use App\Services\Billing\PlanLine;
use App\Services\Billing\Quotas;
use App\Services\Platform\PlatformOverview;
use App\Services\Team\Permissions;
use App\Support\AppFamily;
use App\Support\Rules\CompanyRules;
use Database\Seeders\UnityDeskPlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesUnity;
use Tests\Feature\Api\ApiTestCase;

/**
 * UnityDesk (slug unitydesk, module unity) is registered everywhere an app is: the app family, the modules, the plan line and
 * its unity_members quota, the permissions and roles, and the platform overview.
 */
class UnityRegistryTest extends ApiTestCase
{
    use MakesUnity;

    public function test_the_app_is_known_by_its_slug_and_the_name_the_phone_sends(): void
    {
        $this->assertSame('unitydesk', AppFamily::slug('unitydesk'), 'the phone sends app=unitydesk');
        $this->assertSame('unitydesk', AppFamily::slug('unity'));
        $this->assertSame('UnityDesk', AppFamily::name('unitydesk'));
        $this->assertSame('unity', AppFamily::module('unitydesk'));
        $this->assertTrue(AppFamily::ownBooks('unitydesk'));
        $this->assertContains('unity', AppFamily::OWN_BOOKS_MODULES);
        $this->assertSame('budget-pro', AppFamily::slug('budget-pro'), 'Budget Pro stays for the web');
    }

    public function test_the_module_is_declared_everywhere(): void
    {
        $this->assertContains('unity', EnsureApiModule::MODULES);
        $this->assertContains('unity', CompanyRules::CORE_MODULES);
        $this->assertContains('unity', CompanyRules::EXCLUSIVE_MODULES);
        $this->assertArrayHasKey('unity', config('onboarding.modules'));
        $this->assertSame(['unity'], config('onboarding.business_types.unity.modules'));
        $this->assertSame([], config('onboarding.module_paths.unity'));
        $this->assertArrayNotHasKey('unity', CompanyRules::familyBusinessTypes(), 'only UnityDesk signs an association up');
        $this->assertSame('unitydesk', PlatformOverview::appOf(['unity']));
        $this->assertSame('#2B3A67', config('unity.brand.primary'));
        $this->assertSame('#1F8A70', config('unity.brand.accent'));
        $this->assertSame('#F7F8FA', config('unity.brand.background'));
    }

    public function test_plans_line_and_unity_members_quota(): void
    {
        $this->seed(UnityDeskPlanSeeder::class);
        $this->assertSame(PlanLine::UNITYDESK, PlanLine::forModules(['unity']));
        $this->assertSame('unitydesk-trial', PlanLine::trialSlug(PlanLine::UNITYDESK));
        $this->assertSame('unitydesk-free', PlanLine::freeSlug(PlanLine::UNITYDESK));
        $plan = fn (string $slug) => Plan::where('slug', $slug)->firstOrFail();
        $this->assertSame(30, (int) $plan('unitydesk-trial')->trial_days);
        $this->assertSame(100, $plan('unitydesk-free')->limits['max_unity_members']);
        $this->assertSame(300, $plan('unitydesk-free')->limits['storage_mb']);
        $this->assertSame(1000, $plan('unitydesk-group')->limits['max_unity_members']);
        $this->assertSame(2000, $plan('unitydesk-group')->limits['storage_mb']);
        $this->assertSame(25000, (int) $plan('unitydesk-group')->price_ugx);
        $this->assertSame(250000, (int) $plan('unitydesk-group')->price_ugx_annual);
        $this->assertNull($plan('unitydesk-organisation')->limits['max_unity_members'] ?? null, 'Organisation: no limit');
        $this->assertSame(10000, $plan('unitydesk-organisation')->limits['storage_mb']);
        $this->assertSame(60000, (int) $plan('unitydesk-organisation')->price_ugx);
        $this->assertSame(600000, (int) $plan('unitydesk-organisation')->price_ugx_annual);
        foreach (['unitydesk-trial', 'unitydesk-free', 'unitydesk-group', 'unitydesk-organisation'] as $slug) {
            $this->assertSame(PlanLine::UNITYDESK, PlanLine::of($plan($slug)));
        }

        [$company] = $this->unityAccount();
        $this->assertSame(['unity'], $company->modules());
        $this->assertSame('unity', $company->business_type);
        $this->assertSame(PlanLine::UNITYDESK, PlanLine::for($company));
        $row = fn (string $status, array $more = []) => DB::table('sacco_members')->insert($more + ['company_id' => $company->id, 'uuid' => (string) Str::uuid(),
            'first_name' => 'Sample', 'last_name' => 'Member', 'status' => $status, 'joined_on' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $row('active');
        $row('applicant');
        $row('suspended');
        $row('exited');
        $row('active', ['is_deleted' => 1]);
        $usage = (new Quotas())->usage($company);
        $this->assertSame(3, $usage['unity_members']['used'], 'active, applicant and suspended count; exited and deleted do not');
        $this->assertArrayNotHasKey('members', $usage, 'no SACCO quota on an association');
        $this->assertArrayNotHasKey('people', $usage);
    }

    public function test_roles_and_permissions_are_the_associations_own(): void
    {
        [$company] = $this->unityAccount();
        $id = (int) $company->id;
        $this->assertSame(['owner', 'viewer', 'unity_secretary', 'unity_treasurer', 'unity_chapter_lead', 'unity_member'], Permissions::rolesFor($id));
        $available = Permissions::availableFor($id);
        foreach (['unity_view', 'unity_members', 'unity_finance', 'unity_chapters', 'unity_meetings', 'unity_welfare', 'unity_voice', 'unity_settings', 'manage_team'] as $p) {
            $this->assertContains($p, $available);
        }
        $this->assertNotContains('sell', $available);
        $this->assertNotContains('sacco_view', $available);
        $this->assertNotContains('kin_view', $available);
        $this->assertSame(['unity_view', 'unity_members', 'unity_chapters', 'unity_meetings', 'unity_voice'], Permissions::forRole($id, 'unity_secretary'));
        $this->assertSame(['unity_view', 'unity_finance', 'unity_welfare'], Permissions::forRole($id, 'unity_treasurer'));
        $this->assertSame(['unity_view', 'unity_members', 'unity_meetings'], Permissions::forRole($id, 'unity_chapter_lead'));
        $this->assertSame([], Permissions::forRole($id, 'unity_member'), 'a member reads only their own record');
        $this->assertContains('unity_view', Permissions::forRole($id, 'viewer'));
        $this->assertSame('shop_viewer', config('permissions.admin_roles.unity_member'));
    }
}

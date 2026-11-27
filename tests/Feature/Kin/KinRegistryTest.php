<?php

namespace Tests\Feature\Kin;

use App\Http\Middleware\EnsureApiModule;
use App\Models\Plan;
use App\Services\Billing\PlanLine;
use App\Services\Billing\Quotas;
use App\Services\Platform\PlatformOverview;
use App\Services\Team\Permissions;
use App\Support\AppFamily;
use App\Support\Rules\CompanyRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/**
 * My Family Roots 360 (slug family-roots, module kin) is registered everywhere an app is: the app family, the modules, the plan
 * line and its quota, the permissions and roles, and the platform overview.
 */
class KinRegistryTest extends ApiTestCase
{
    use MakesKin;

    public function test_the_app_is_known_by_its_slug_and_the_names_the_phone_sends(): void
    {
        $this->assertSame('family-roots', AppFamily::slug('family-roots'));
        $this->assertSame('family-roots', AppFamily::slug('kin'), 'the phone sends app=kin');
        $this->assertSame('family-roots', AppFamily::slug('FamilyRoots'));
        $this->assertSame('Family Roots', AppFamily::name('family-roots'));
        $this->assertSame('kin', AppFamily::module('family-roots'));
        $this->assertTrue(AppFamily::ownBooks('family-roots'));
        $this->assertContains('kin', AppFamily::OWN_BOOKS_MODULES);
    }

    public function test_the_module_is_declared_everywhere(): void
    {
        $this->assertContains('kin', EnsureApiModule::MODULES);
        $this->assertContains('kin', CompanyRules::CORE_MODULES);
        $this->assertContains('kin', CompanyRules::EXCLUSIVE_MODULES);
        $this->assertArrayHasKey('kin', config('onboarding.modules'));
        $this->assertSame(['kin'], config('onboarding.business_types.kin.modules'));
        $this->assertSame([], config('onboarding.module_paths.kin'));
        $this->assertArrayNotHasKey('kin', CompanyRules::familyBusinessTypes(), 'only Family Roots signs a family up');
        $this->assertTrue(config('kin.purse_enabled'), 'the purse is built (POST kin/purse), so it is on unless KIN_PURSE_ENABLED=false');
        $this->assertSame('family-roots', PlatformOverview::appOf(['kin']));
        $this->assertSame('family-roots', PlatformOverview::appOf(['kin', 'sacco']));
        $this->assertSame('akiba', PlatformOverview::appOf(['sacco']));
    }

    public function test_plans_line_and_people_quota(): void
    {
        $this->seed(\Database\Seeders\FamilyRootsPlanSeeder::class);
        $this->assertSame(PlanLine::FAMILY_ROOTS, PlanLine::forModules(['kin']));
        $this->assertSame(PlanLine::FAMILY_ROOTS, PlanLine::forModules(['kin', 'sacco']), 'a family with a purse stays on its line');
        $this->assertSame(PlanLine::AKIBA, PlanLine::forModules(['sacco']));
        $this->assertSame('family-roots-trial', PlanLine::trialSlug(PlanLine::FAMILY_ROOTS));
        $this->assertSame('family-roots-free', PlanLine::freeSlug(PlanLine::FAMILY_ROOTS));
        $limits = fn (string $slug) => Plan::where('slug', $slug)->firstOrFail()->limits;
        $this->assertSame(150, $limits('family-roots-free')['max_people']);
        $this->assertSame(1000, $limits('family-roots-family')['max_people']);
        $this->assertNull($limits('family-roots-clan')['max_people'] ?? null, 'Clan: no limit');
        foreach (['family-roots-free', 'family-roots-family', 'family-roots-clan'] as $slug) {
            $this->assertSame(PlanLine::FAMILY_ROOTS, PlanLine::of(Plan::where('slug', $slug)->firstOrFail()));
        }

        // A sign-up from the app gets the family's books alone, on the family's trial, and counts its people.
        [$company] = $this->kinAccount();
        $this->assertSame(['kin'], $company->modules());
        $this->assertSame('kin', $company->business_type);
        Plan::query(); // the account was moved to Clan by the helper; check its line instead
        $this->assertSame(PlanLine::FAMILY_ROOTS, PlanLine::for($company));
        $row = fn (string $status, array $more = []) => DB::table('sacco_members')->insert($more + ['company_id' => $company->id, 'uuid' => (string) Str::uuid(),
            'first_name' => 'Sample', 'last_name' => 'Person', 'status' => $status, 'joined_on' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $row('active');
        $row('applicant');
        $row('deceased');
        $row('exited');
        $row('active', ['is_deleted' => 1]);
        $usage = (new Quotas())->usage($company);
        $this->assertSame(3, $usage['people']['used'], 'active, applicant and deceased count; exited and deleted do not');
        $this->assertArrayNotHasKey('members', $usage, 'no SACCO quota on a family');
        $this->assertArrayNotHasKey('units', $usage);
    }

    public function test_roles_and_permissions_are_the_familys_own(): void
    {
        [$company] = $this->kinAccount();
        $roles = Permissions::rolesFor((int) $company->id);
        $this->assertSame(['owner', 'viewer', 'kin_secretary', 'kin_treasurer', 'kin_branch_coordinator', 'kin_member'], $roles);
        $available = Permissions::availableFor((int) $company->id);
        foreach (['kin_view', 'kin_people', 'kin_branches', 'kin_settings', 'manage_team'] as $p) {
            $this->assertContains($p, $available);
        }
        $this->assertNotContains('sell', $available);
        $this->assertNotContains('sacco_view', $available, 'no purse yet');
        $this->assertSame(['kin_view'], Permissions::forRole((int) $company->id, 'kin_member'));
        $this->assertContains('kin_view', Permissions::forRole((int) $company->id, 'viewer'));
        $this->assertSame('shop_viewer', config('permissions.admin_roles.kin_member'));
    }
}

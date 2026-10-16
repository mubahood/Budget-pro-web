<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Support\Rules\CompanyRules;

/**
 * The family of apps on one account (budget-pro-new docs/FAMILY_PLAN.md Parts 1.6 and 2): a specialist
 * app's sign-up sends its own module, every account keeps at least one core module, and setup only
 * asks what applies.
 */
class FamilyModulesTest extends ApiTestCase
{
    private function company(array $t): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($t['company_id']);
    }

    public function test_sign_up_takes_the_apps_modules_with_income_and_expenses_and_the_type_follows(): void
    {
        $farm = $this->registerTenant(['modules' => ['poultry'], 'business_type' => 'retail']);
        $c = $this->company($farm);
        $this->assertSame(['poultry', 'finance'], $c->enabled_modules);
        $this->assertSame('poultry', $c->business_type, 'a farm has no shop type');

        $event = $this->company($this->registerTenant(['modules' => ['budget']]));
        $this->assertSame(['budget', 'finance'], $event->modules());
        $this->assertSame('fundraising', $event->business_type);

        $all = $this->company($this->registerTenant(['modules' => ['budget', 'shop', 'poultry', 'finance'], 'business_type' => 'pharmacy']));
        $this->assertSame(['shop', 'poultry', 'budget', 'finance'], $all->modules(), 'core order, then Income & expenses');
        $this->assertSame('pharmacy', $all->business_type, 'with Shop the shop type is kept');

        // Without `modules` nothing changes: the type decides, as before.
        $old = $this->company($this->registerTenant(['business_type' => 'retail']));
        $this->assertNull($old->enabled_modules);
        $this->assertSame(['shop', 'finance'], $old->modules());
        $this->assertSame('poultry', $this->company($this->registerTenant(['business_type' => 'poultry']))->modules()[0]);
    }

    public function test_sign_up_refuses_a_list_without_a_core_module(): void
    {
        $base = ['first_name' => 'A', 'last_name' => 'B', 'password' => 'secret123', 'company_name' => 'X', 'currency' => 'UGX'];
        $this->postJson('/api/v1/auth/register', $base + ['email' => 'a_'.uniqid().'@example.com', 'modules' => ['finance']])
            ->assertStatus(422)->assertJsonPath('errors.modules.0', CompanyRules::CORE_MODULE_MESSAGE);
        $this->postJson('/api/v1/auth/register', $base + ['email' => 'b_'.uniqid().'@example.com', 'modules' => ['games']])
            ->assertStatus(422)->assertJsonValidationErrors('modules.0');
    }

    public function test_the_business_step_takes_modules_and_setup_skips_the_shop_steps(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $h = $this->auth($t['token']);
        $res = $this->putJson('/api/v1/onboarding/business', ['business_type' => 'retail', 'country' => 'UG', 'modules' => ['poultry']], $h)->assertOk()
            ->assertJsonPath('data.company.modules', ['poultry', 'finance'])
            ->assertJsonPath('data.state.step', 'team');
        $this->assertSame('poultry', $this->company($t)->business_type);
        $this->assertSame(['staff', 'phone_app', 'plan'], collect($res->json('data.checklist_v2.items'))->pluck('key')->all(), 'no products, sale or receipts for a farm');
        $this->postJson('/api/v1/onboarding/steps/team', ['skipped' => true], $h)->assertOk()->assertJsonPath('data.state.step', 'done');

        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'retail', 'country' => 'UG', 'modules' => ['finance']], $h)
            ->assertStatus(422)->assertJsonPath('errors.modules.0', CompanyRules::CORE_MODULE_MESSAGE);

        // A shop still walks every step.
        $shop = $this->registerTenant(['currency' => 'UGX']);
        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'retail', 'country' => 'UG', 'modules' => ['shop']], $this->auth($shop['token']))->assertOk()
            ->assertJsonPath('data.company.modules', ['shop', 'finance'])->assertJsonPath('data.state.step', 'products')
            ->assertJsonPath('data.checklist_v2.total', 8);
    }

    public function test_every_account_keeps_a_core_module(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX', 'business_type' => 'retail']);
        $h = $this->auth($t['token']);
        $this->putJson('/api/v1/company/modules', ['modules' => ['finance']], $h)
            ->assertStatus(422)->assertJsonPath('errors.modules.0', CompanyRules::CORE_MODULE_MESSAGE);
        $this->putJson('/api/v1/company', ['enabled_modules' => ['finance']], $h)
            ->assertStatus(422)->assertJsonPath('errors.enabled_modules.0', CompanyRules::CORE_MODULE_MESSAGE);
        $this->assertSame(['shop', 'finance'], $this->company($t)->modules(), 'nothing changed');

        $this->putJson('/api/v1/company/modules', ['modules' => ['budget', 'finance']], $h)->assertOk()->assertJsonPath('data.modules', ['budget', 'finance']);
        $this->putJson('/api/v1/company', ['enabled_modules' => ['poultry']], $h)->assertOk();
        $this->assertSame(['poultry'], $this->company($t)->modules());
    }

    public function test_the_sign_up_helpers(): void
    {
        $this->assertNull(CompanyRules::signUpModules(null));
        $this->assertNull(CompanyRules::signUpModules(['finance']));
        $this->assertSame(['shop', 'budget', 'finance'], CompanyRules::signUpModules(['budget', 'shop', 'shop']));
        $this->assertSame('kiosk', CompanyRules::businessTypeFor(['shop', 'finance'], 'kiosk'));
        $this->assertSame('poultry', CompanyRules::businessTypeFor(['poultry', 'budget', 'finance'], 'kiosk'));
        $this->assertSame('fundraising', CompanyRules::businessTypeFor(['budget', 'finance'], 'kiosk'));
    }
}

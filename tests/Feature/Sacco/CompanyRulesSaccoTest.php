<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsureApiModule;
use App\Models\Company;
use App\Support\Rules\CompanyRules;
use App\Support\SupportContact;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.1 (docs/akiba/05-roadmap.md, AK-04): `sacco` is a module of its own that stands alone.
 * A SACCO account has the SACCO books and nothing else (not even Income & expenses), no list may put it beside
 * another module, and no account moves into or out of it later. Every family account is unchanged.
 */
class CompanyRulesSaccoTest extends ApiTestCase
{
    private function company(array $t): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($t['company_id']);
    }

    public function test_the_module_is_known_everywhere_it_is_declared(): void
    {
        $this->assertArrayHasKey('sacco', config('onboarding.modules'));
        $this->assertSame([], config('onboarding.module_paths.sacco'), 'no classic screens (AK-20)');
        $this->assertSame(['sacco'], config('onboarding.business_types.sacco.modules'));
        $this->assertContains('sacco', EnsureApiModule::MODULES);
        $this->assertContains('sacco', CompanyRules::CORE_MODULES);
        $this->assertSame(['sacco'], CompanyRules::EXCLUSIVE_MODULES);
        $this->assertContains('akiba_ledger', SupportContact::APPS);
        $this->assertSame('SACCO and savings group', EnsureApiModule::label('sacco'));
    }

    public function test_a_sacco_sign_up_gets_the_sacco_books_alone(): void
    {
        // What the Akiba Ledger app and ?app=akiba-ledger send.
        $t = $this->registerTenant(['modules' => ['sacco'], 'business_type' => 'retail']);
        $c = $this->company($t);
        $this->assertSame(['sacco'], $c->enabled_modules, 'no Income & expenses for a SACCO');
        $this->assertSame(['sacco'], $c->modules());
        $this->assertSame('sacco', $c->business_type);

        // The business type alone says the same.
        $this->assertSame(['sacco'], $this->company($this->registerTenant(['business_type' => 'sacco']))->modules());
    }

    public function test_sacco_beside_another_module_is_refused(): void
    {
        $base = ['first_name' => 'A', 'last_name' => 'B', 'password' => 'secret123', 'company_name' => 'X', 'currency' => 'UGX'];
        $this->postJson('/api/v1/auth/register', $base + ['email' => 'sx_'.uniqid().'@example.com', 'modules' => ['sacco', 'shop']])
            ->assertStatus(422)->assertJsonPath('errors.modules.0', CompanyRules::EXCLUSIVE_MESSAGE);
        $this->postJson('/api/v1/auth/register', $base + ['email' => 'sy_'.uniqid().'@example.com', 'modules' => ['sacco', 'finance']])
            ->assertStatus(422)->assertJsonPath('errors.modules.0', CompanyRules::EXCLUSIVE_MESSAGE);

        $saccoT = $this->registerTenant(['modules' => ['sacco']]);
        $h = $this->auth($saccoT['token']);
        $this->putJson('/api/v1/company/modules', ['modules' => ['sacco', 'shop']], $h)
            ->assertStatus(422)->assertJsonPath('errors.modules.0', CompanyRules::EXCLUSIVE_MESSAGE);
        $this->putJson('/api/v1/company', ['enabled_modules' => ['sacco', 'budget']], $h)
            ->assertStatus(422)->assertJsonPath('errors.enabled_modules.0', CompanyRules::EXCLUSIVE_MESSAGE);
        $this->assertSame(['sacco'], $this->company($saccoT)->modules(), 'nothing changed');
    }

    public function test_no_account_moves_into_or_out_of_the_sacco_books(): void
    {
        $sacco = $this->registerTenant(['modules' => ['sacco']]);
        $this->putJson('/api/v1/company/modules', ['modules' => ['shop']], $this->auth($sacco['token']))
            ->assertStatus(422)->assertJsonPath('errors.code', 'module_exclusive');

        $shop = $this->registerTenant(['business_type' => 'retail']);
        $h = $this->auth($shop['token']);
        $this->putJson('/api/v1/company/modules', ['modules' => ['sacco']], $h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'module_exclusive')->assertJsonPath('message', CompanyRules::EXCLUSIVE_MESSAGE);
        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'sacco', 'country' => 'UG'], $h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'module_exclusive');
        $this->assertSame(['shop', 'finance'], $this->company($shop)->modules());

        // Whoever writes the model (the portal, the classic admin) meets the same rule.
        $model = $this->company($shop);
        $model->enabled_modules = ['sacco'];
        try {
            $model->save();
            $this->fail('a shop may not become a SACCO');
        } catch (BusinessRuleException $e) {
            $this->assertSame('module_exclusive', $e->errorCode());
        }
    }

    public function test_family_accounts_are_unchanged(): void
    {
        $shop = $this->company($this->registerTenant(['modules' => ['shop']]));
        $this->assertSame(['shop', 'finance'], $shop->modules());
        $this->assertSame(['shop', 'finance', 'budget', 'poultry'], (new Company())->modules(), 'an account that never chose sees every family module, not the SACCO books');
        $this->assertSame(['shop', 'budget', 'finance'], CompanyRules::signUpModules(['budget', 'shop']));
        $this->assertSame(['sacco'], CompanyRules::signUpModules(['sacco']));
        $this->assertSame('sacco', CompanyRules::businessTypeFor(['sacco'], 'kiosk'));
        $this->assertTrue(CompanyRules::exclusiveOk(['shop', 'poultry', 'finance']));
        $this->assertFalse(CompanyRules::exclusiveOk(['sacco', 'finance']));

        $t = $this->registerTenant(['business_type' => 'retail']);
        $this->putJson('/api/v1/company/modules', ['modules' => ['budget', 'finance']], $this->auth($t['token']))->assertOk()->assertJsonPath('data.modules', ['budget', 'finance']);
    }
}

<?php

namespace Tests\Feature\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Services\Billing\PlanLine;
use App\Support\AppFamily;
use App\Support\Rules\CompanyRules;
use App\Support\SupportContact;
use Tests\Concerns\MakesUnity;
use Tests\Feature\Api\ApiTestCase;

/**
 * An account with `unity` opens in UnityDesk only, and no other own-books account opens there: unity stands alone with no
 * mix. The kin rules (kin wins over sacco) and the SACCO and rentals rules are unchanged.
 */
class UnityGuardTest extends ApiTestCase
{
    use MakesUnity;

    public function test_an_association_opens_only_in_unitydesk_and_the_reverse(): void
    {
        foreach (array_keys(AppFamily::APPS) as $slug) {
            $this->assertSame($slug === 'unitydesk', AppFamily::opens(['unity'], $slug), 'unity in '.$slug);
        }
        foreach (['akiba-ledger', 'estate-ledger', 'family-roots'] as $slug) {
            $this->assertFalse(AppFamily::opens(['unity'], $slug), 'unity never opens in '.$slug);
        }
        foreach ([['sacco'], ['rentals'], ['kin'], ['kin', 'sacco'], ['shop', 'finance'], ['budget']] as $modules) {
            $this->assertFalse(AppFamily::opens($modules, 'unitydesk'), implode(',', $modules).' never opens in UnityDesk');
        }
        $this->assertTrue(AppFamily::opens(['sacco'], 'akiba-ledger'), 'unchanged');
        $this->assertTrue(AppFamily::opens(['rentals'], 'estate-ledger'), 'unchanged');
        $this->assertTrue(AppFamily::opens(['kin'], 'family-roots'), 'unchanged');
        $this->assertTrue(AppFamily::opens(['shop', 'finance'], 'budget-pro'), 'unchanged');
    }

    public function test_unity_stands_alone_and_kin_still_wins_over_sacco(): void
    {
        $this->assertSame('unity', CompanyRules::exclusiveOf(['unity']));
        $this->assertSame('kin', CompanyRules::exclusiveOf(['sacco', 'kin']), 'kin rules unchanged');
        $this->assertTrue(CompanyRules::exclusiveOk(['unity']));
        foreach ([['unity', 'sacco'], ['unity', 'kin'], ['unity', 'finance'], ['unity', 'shop'], ['unity', 'rentals']] as $mix) {
            $this->assertFalse(CompanyRules::exclusiveOk($mix), implode(',', $mix));
        }
        config(['kin.purse_enabled' => true]);
        $this->assertFalse(CompanyRules::exclusiveOk(['unity', 'sacco']), 'the purse is a family\'s, never an association\'s');
        $this->assertTrue(CompanyRules::exclusiveOk(['kin', 'sacco']), 'kin purse unchanged');
        $this->assertSame(['unity'], CompanyRules::signUpModules(['unity', 'shop']));
        $this->assertSame('unity', CompanyRules::businessTypeFor(['unity'], 'retail'));
        // A shop that tries to take the module never hears UnityDesk named.
        $this->assertStringNotContainsString('UnityDesk', CompanyRules::exclusiveMessage(['shop', 'unity'], ['shop']));
    }

    public function test_an_association_never_moves_out_and_nothing_moves_in(): void
    {
        [$company] = $this->unityAccount();
        $save = function (int $id, array $modules) {
            $c = Company::withoutGlobalScopes()->findOrFail($id);
            $c->enabled_modules = $modules;
            $c->save();
        };
        foreach ([['shop', 'finance'], ['sacco'], ['kin'], ['unity', 'sacco']] as $modules) {
            try {
                $save((int) $company->id, $modules);
                $this->fail('moved to '.implode(',', $modules));
            } catch (BusinessRuleException) {
                $this->assertSame(['unity'], Company::withoutGlobalScopes()->findOrFail($company->id)->modules());
            }
        }
        $c = Company::withoutGlobalScopes()->findOrFail($company->id);
        $this->assertSame('unity', SupportContact::standaloneApp($c));
        $this->assertFalse(SupportContact::isSacco($c));
        $this->assertSame('UnityDesk', SupportContact::productName($c));
        $this->assertSame(PlanLine::UNITYDESK, PlanLine::for($c));

        $t = $this->registerTenant(['modules' => ['sacco']]);
        $this->expectException(BusinessRuleException::class);
        $save((int) $t['company_id'], ['unity']);
    }
}

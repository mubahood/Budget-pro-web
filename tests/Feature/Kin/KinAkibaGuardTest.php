<?php

namespace Tests\Feature\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Services\Billing\PlanLine;
use App\Services\Team\Permissions;
use App\Support\AppFamily;
use App\Support\Rules\CompanyRules;
use App\Support\SupportContact;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/**
 * The Akiba guard (doc 11 T1): an account with `kin` opens in Family Roots only, never as a SACCO, even when a family's purse
 * puts `sacco` beside it. kin stands alone, and the one mix allowed is ['kin', 'sacco'] while kin.purse_enabled is on.
 * The SACCO and rentals rules are unchanged.
 */
class KinAkibaGuardTest extends ApiTestCase
{
    use MakesKin;

    public function test_a_family_opens_only_in_family_roots(): void
    {
        foreach ([['kin'], ['kin', 'sacco'], ['sacco', 'kin']] as $modules) {
            foreach (array_keys(AppFamily::APPS) as $slug) {
                $this->assertSame($slug === 'family-roots', AppFamily::opens($modules, $slug), implode(',', $modules).' in '.$slug);
            }
        }
        // Unchanged: a SACCO in Akiba, rentals in Estate Ledger, a shop in the family apps.
        $this->assertTrue(AppFamily::opens(['sacco'], 'akiba-ledger'));
        $this->assertFalse(AppFamily::opens(['sacco'], 'family-roots'));
        $this->assertTrue(AppFamily::opens(['rentals'], 'estate-ledger'));
        $this->assertFalse(AppFamily::opens(['shop', 'finance'], 'family-roots'));
        $this->assertTrue(AppFamily::opens(['shop', 'finance'], 'budget-pro'));
    }

    public function test_exclusive_of_puts_kin_first(): void
    {
        $this->assertSame('kin', CompanyRules::exclusiveOf(['kin']));
        $this->assertSame('kin', CompanyRules::exclusiveOf(['kin', 'sacco']));
        $this->assertSame('kin', CompanyRules::exclusiveOf(['sacco', 'kin']));
        $this->assertSame('kin', CompanyRules::exclusiveOf(['shop', 'kin', 'rentals']));
        $this->assertSame('sacco', CompanyRules::exclusiveOf(['sacco']));
        $this->assertSame('rentals', CompanyRules::exclusiveOf(['rentals']));
        $this->assertNull(CompanyRules::exclusiveOf(['shop', 'finance']));
    }

    public function test_exclusive_ok_allows_the_purse_only_with_the_flag(): void
    {
        config(['kin.purse_enabled' => false]);
        $this->assertTrue(CompanyRules::exclusiveOk(['kin']));
        $this->assertTrue(CompanyRules::exclusiveOk(['sacco']));
        $this->assertTrue(CompanyRules::exclusiveOk(['rentals']));
        $this->assertTrue(CompanyRules::exclusiveOk(['shop', 'finance']));
        $this->assertFalse(CompanyRules::exclusiveOk(['kin', 'sacco']), 'no purse while the flag is off');
        $this->assertFalse(CompanyRules::exclusiveOk(['kin', 'finance']));
        $this->assertFalse(CompanyRules::exclusiveOk(['kin', 'shop']));
        $this->assertFalse(CompanyRules::exclusiveOk(['kin', 'rentals']));

        config(['kin.purse_enabled' => true]);
        $this->assertTrue(CompanyRules::exclusiveOk(['kin', 'sacco']));
        $this->assertTrue(CompanyRules::exclusiveOk(['sacco', 'kin']));
        $this->assertFalse(CompanyRules::exclusiveOk(['kin', 'sacco', 'finance']));
        $this->assertFalse(CompanyRules::exclusiveOk(['kin', 'rentals']));
        $this->assertFalse(CompanyRules::exclusiveOk(['sacco', 'rentals']), 'a SACCO still stands alone');
        $this->assertFalse(CompanyRules::exclusiveOk(['sacco', 'finance']));
    }

    public function test_a_family_account_takes_a_purse_only_with_the_flag_and_never_moves_out(): void
    {
        [$company] = $this->kinAccount();
        $save = function (array $modules) use ($company) {
            $c = Company::withoutGlobalScopes()->findOrFail($company->id);
            $c->enabled_modules = $modules;
            $c->save();
        };
        config(['kin.purse_enabled' => false]);
        try {
            $save(['kin', 'sacco']);
            $this->fail('a purse without the flag');
        } catch (BusinessRuleException $e) {
            $this->assertSame(['exclusive' => ['kin']], array_intersect_key($e->toErrors(), ['exclusive' => 1]));
            $this->assertStringContainsString('My Family Roots 360', $e->getMessage());
        }
        foreach ([['shop', 'finance'], ['sacco'], ['rentals']] as $modules) {
            try {
                $save($modules);
                $this->fail('moved out to '.implode(',', $modules));
            } catch (BusinessRuleException) {
                $this->assertSame(['kin'], Company::withoutGlobalScopes()->findOrFail($company->id)->modules());
            }
        }

        config(['kin.purse_enabled' => true]);
        $save(['kin', 'sacco']);
        $c = Company::withoutGlobalScopes()->findOrFail($company->id);
        $this->assertSame(['kin', 'sacco'], $c->modules());
        $this->assertTrue(AppFamily::opens($c->modules(), 'family-roots'));
        $this->assertFalse(AppFamily::opens($c->modules(), 'akiba-ledger'), 'never as a SACCO');
        $this->assertSame('kin', SupportContact::standaloneApp($c));
        $this->assertFalse(SupportContact::isSacco($c), 'its mail never speaks as Akiba');
        $this->assertSame('My Family Roots 360', SupportContact::productName($c));
        $this->assertSame(PlanLine::FAMILY_ROOTS, PlanLine::for($c));
        Permissions::flush();
        $this->assertContains('sacco_view', Permissions::availableFor((int) $c->id), 'the purse brings its permissions');
        $this->assertSame(['owner', 'viewer', 'kin_secretary', 'kin_treasurer', 'kin_branch_coordinator', 'kin_member'], Permissions::rolesFor((int) $c->id), 'but the family\'s roles only');
    }

    public function test_a_sacco_never_moves_into_a_family(): void
    {
        config(['kin.purse_enabled' => true]);
        $t = $this->registerTenant(['modules' => ['sacco']]);
        $c = Company::withoutGlobalScopes()->findOrFail($t['company_id']);
        $c->enabled_modules = ['kin', 'sacco'];
        $this->expectException(BusinessRuleException::class);
        $c->save();
    }

    public function test_sign_up_modules_keep_kin_alone(): void
    {
        $this->assertSame(['kin'], CompanyRules::signUpModules(['kin']));
        $this->assertSame(['kin'], CompanyRules::signUpModules(['kin', 'shop']));
        $this->assertSame('kin', CompanyRules::businessTypeFor(['kin'], 'retail'));
        $this->assertSame(['sacco'], CompanyRules::signUpModules(['sacco']), 'unchanged');
        $this->assertSame(['rentals'], CompanyRules::signUpModules(['rentals']), 'unchanged');
    }
}

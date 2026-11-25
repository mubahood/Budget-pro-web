<?php

namespace Tests\Feature\Kin;

use App\Models\Company;
use App\Services\Onboarding\RegistrationService;
use App\Services\Onboarding\SiblingAccounts;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/** Someone with a shop who starts Family Roots: a second account with the family's books alone, on the same sign-in. */
class KinSiblingAccountTest extends ApiTestCase
{
    private const PASS = 'Shop-Owner-2026!x';

    public function test_a_shop_owner_starting_family_roots_gets_a_second_account_on_the_same_login(): void
    {
        SiblingAccounts::forgetReadiness();
        $r = app(RegistrationService::class)->register(['first_name' => 'Asha', 'last_name' => 'Sample', 'email' => 'kin.sib@example.com', 'password' => self::PASS,
            'company_name' => 'Sample Shop', 'currency' => 'UGX', 'country' => 'UG', 'business_type' => 'retail', 'modules' => ['shop', 'finance']]);
        $owner = $r['user']->fresh();
        $shop = $r['company']->fresh();
        $before = $shop->modules();
        $h = $this->auth($owner->createToken('t')->plainTextToken);

        // The phone sends app=kin.
        $d = $this->postJson('/api/v1/auth/sibling', ['app' => 'kin', 'company_name' => 'The Sample Family', 'device_name' => 'test'], $h)->assertOk()->json('data');
        $family = Company::withoutGlobalScopes()->findOrFail($d['company']['id']);
        $this->assertNotSame((int) $shop->id, (int) $family->id);
        $this->assertSame(['kin'], $family->modules());
        $this->assertSame($before, $shop->fresh()->modules(), 'the shop is not touched');
        $this->assertSame('family-roots', DB::table('account_siblings')->where('user_id', $owner->id)->value('app'));

        // One sign-in: the app decides which account opens.
        $in = fn (?string $app) => $this->postJson('/api/v1/auth/login', array_filter(['identifier' => $owner->email, 'password' => self::PASS, 'device_name' => 'x', 'app' => $app]))
            ->assertOk()->json('data.company.id');
        $this->assertSame((int) $family->id, (int) $in('kin'));
        $this->assertSame((int) $family->id, (int) $in('family-roots'));
        $this->assertSame((int) $shop->id, (int) $in('budget-pro'));
        $this->assertSame((int) $shop->id, (int) $in('akiba-ledger'), 'a family is never opened as a SACCO');

        // Asking again finds the same account.
        app('auth')->forgetGuards();
        $again = $this->postJson('/api/v1/auth/sibling', ['app' => 'family-roots', 'company_name' => 'Other', 'device_name' => 'test'], $this->auth($owner->createToken('t2')->plainTextToken))
            ->assertOk()->json('data.company.id');
        $this->assertSame((int) $family->id, (int) $again);
    }
}

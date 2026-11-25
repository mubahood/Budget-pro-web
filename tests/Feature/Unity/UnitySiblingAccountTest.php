<?php

namespace Tests\Feature\Unity;

use App\Models\Company;
use App\Services\Onboarding\RegistrationService;
use App\Services\Onboarding\SiblingAccounts;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * Budget Pro's phone package becomes UnityDesk. A shop owner who signs in to it is told plainly they have no association
 * (data.access.handoff), nothing is created for them, and only when they ask does a second account open on the same sign-in.
 */
class UnitySiblingAccountTest extends ApiTestCase
{
    private const PASS = 'Shop-Owner-2026!x';

    public function test_a_shop_owner_signing_in_to_unitydesk_gets_a_handoff_then_a_second_account_only_when_asked(): void
    {
        SiblingAccounts::forgetReadiness();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // eight sign-ins in a row
        $r = app(RegistrationService::class)->register(['first_name' => 'Asha', 'last_name' => 'Sample', 'email' => 'unity.sib@example.com', 'password' => self::PASS,
            'company_name' => 'Sample Shop', 'currency' => 'UGX', 'country' => 'UG', 'business_type' => 'retail', 'modules' => ['shop', 'finance']]);
        $owner = $r['user']->fresh();
        $shop = $r['company']->fresh();
        $before = $shop->modules();
        $companies = Company::withoutGlobalScopes()->count();
        $login = fn (?string $app) => $this->postJson('/api/v1/auth/login', array_filter(['identifier' => $owner->email, 'password' => self::PASS, 'device_name' => 'x', 'app' => $app]))
            ->assertOk()->json('data');

        // The handoff: signed in to their own books, told UnityDesk is not theirs yet, nothing made.
        $d = $login('unitydesk');
        $this->assertSame((int) $shop->id, (int) $d['company']['id']);
        $this->assertSame(['app' => 'unitydesk', 'name' => 'UnityDesk', 'has' => false, 'handoff' => true, 'add' => 'sibling', 'can_add' => true, 'books' => null], $d['access']);
        $this->assertSame($companies, Company::withoutGlobalScopes()->count(), 'no account is created silently');
        $this->assertSame(0, DB::table('account_siblings')->where('user_id', $owner->id)->count());

        // Asked: a second account with the association's books alone.
        $h = $this->auth($owner->createToken('t')->plainTextToken);
        $s = $this->postJson('/api/v1/auth/sibling', ['app' => 'unitydesk', 'company_name' => 'Sample Association', 'device_name' => 'test'], $h)->assertOk()->json('data');
        $assoc = Company::withoutGlobalScopes()->findOrFail($s['company']['id']);
        $this->assertNotSame((int) $shop->id, (int) $assoc->id);
        $this->assertSame(['unity'], $assoc->modules());
        $this->assertSame($before, $shop->fresh()->modules(), 'the shop is not touched');
        $this->assertSame('unitydesk', DB::table('account_siblings')->where('user_id', $owner->id)->value('app'));

        // One sign-in: the app decides which account opens.
        app('auth')->forgetGuards();
        $in = $login('unitydesk');
        $this->assertSame((int) $assoc->id, (int) $in['company']['id']);
        $this->assertTrue($in['access']['has']);
        $this->assertFalse($in['access']['handoff']);
        $this->assertSame((int) $assoc->id, (int) $login('unity')['company']['id']);
        $this->assertSame((int) $shop->id, (int) $login('budget-pro')['company']['id']);
        foreach (['akiba-ledger', 'estate-ledger', 'family-roots'] as $app) {
            $this->assertNotSame((int) $assoc->id, (int) $login($app)['company']['id'], 'an association never opens in '.$app);
        }

        // Asking again finds the same account.
        app('auth')->forgetGuards();
        $again = $this->postJson('/api/v1/auth/sibling', ['app' => 'unitydesk', 'company_name' => 'Other', 'device_name' => 'test'], $this->auth($owner->createToken('t2')->plainTextToken))
            ->assertOk()->json('data.company.id');
        $this->assertSame((int) $assoc->id, (int) $again);
    }
}

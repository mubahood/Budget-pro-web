<?php

namespace Tests\Feature\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\KinBranch;
use App\Models\KinLink;
use App\Models\KinSpouse;
use App\Models\SaccoMember;
use App\Services\Kin\Demo\KinDemo;
use App\Services\Onboarding\PublicDemo;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/** The demo family: built from invented names, about sixty people over five generations, one sign-in per role. */
class KinDemoTest extends ApiTestCase
{
    public function test_the_demo_family_is_built_signed_into_and_locked(): void
    {
        $r = app(KinDemo::class)->run(true);
        $this->assertContains($r['action'], ['built', 'rebuilt']);
        $company = KinDemo::company();
        $this->assertNotNull($company);
        $cid = (int) $company->id;
        $this->assertSame(['kin'], $company->modules());

        $people = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->count();
        $this->assertGreaterThanOrEqual(50, $people);
        $this->assertLessThanOrEqual(75, $people);
        $this->assertSame(5, (int) SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->max('generation'));
        $this->assertSame(3, KinBranch::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertTrue(KinLink::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'adoptive')->exists());
        $this->assertTrue(KinLink::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'guardian')->exists());
        $this->assertTrue(KinSpouse::withoutGlobalScopes()->where('company_id', $cid)->whereNull('spouse_id')->whereNotNull('spouse_name')->exists());
        $this->assertGreaterThan(2, SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'deceased')->whereNotNull('date_of_death')->count());
        $this->assertSame(0, SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->whereNotNull('phone_e164')->count(), 'no made-up phone numbers');

        // Every role signs in with the published password; the phone's "try the demo" (app=kin) opens the family.
        foreach (array_keys(KinDemo::roles()) as $key) {
            $res = $this->postJson('/api/v1/auth/login', ['email' => KinDemo::email($key), 'password' => KinDemo::password()]);
            $this->assertSame(200, $res->status(), $key.': '.$res->getContent());
        }
        $this->getJson('/api/v1/demo/session?app=kin')->assertOk()->assertJsonCount(5, 'data.roles');
        $token = $this->postJson('/api/v1/demo/session', ['app' => 'kin', 'role' => 'member'])->assertOk()->assertJsonPath('data.app', 'family-roots')->json('data.token');
        $this->getJson('/api/v1/kin/tree', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('data.generations', 5);

        // Visitors cannot add to the team or change a demo sign-in.
        try {
            PublicDemo::guard($company, 'adding people');
            $this->fail('the demo family is locked');
        } catch (BusinessRuleException $e) {
            $this->assertSame('demo_locked', $e->errorCode());
            $this->assertStringNotContainsString('Budget Pro', $e->getMessage());
        }

        // A rebuild replaces it, and the old family is gone.
        $again = app(KinDemo::class)->run(true);
        $this->assertSame('rebuilt', $again['action']);
        $this->assertFalse(DB::table('companies')->where('id', $cid)->exists());
        $this->assertSame(0, SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame('healed', app(KinDemo::class)->run()['action']);
    }
}

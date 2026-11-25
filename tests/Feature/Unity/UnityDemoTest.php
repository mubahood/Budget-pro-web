<?php

namespace Tests\Feature\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoMember;
use App\Models\UnityChapter;
use App\Services\Onboarding\PublicDemo;
use App\Services\Unity\Demo\UnityDemo;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/** The demo association: 80 invented members, four chapters, a mix of standing, one sign-in per role, locked. */
class UnityDemoTest extends ApiTestCase
{
    public function test_the_demo_association_is_built_signed_into_and_locked(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $r = app(UnityDemo::class)->run(true);
        $this->assertContains($r['action'], ['built', 'rebuilt']);
        $company = UnityDemo::company();
        $this->assertNotNull($company);
        $cid = (int) $company->id;
        $this->assertSame(['unity'], $company->modules());
        $this->assertSame('Sample Association (Demo)', $company->name);

        $this->assertSame(80, SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame(4, UnityChapter::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame(['Life', 'Ordinary annual', 'Student annual'], DB::table('unity_categories')->where('company_id', $cid)->orderBy('name')->pluck('name')->all());
        $this->assertSame(0, SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->whereNotNull('phone_e164')->count(), 'no made-up phone numbers');

        $sum = \App\Services\Unity\Memberships::summary($cid);
        $this->assertGreaterThan(40, $sum['good_standing']);
        $this->assertGreaterThan(5, $sum['lapsed']);
        $this->assertGreaterThan(3, $sum['expiring_30_days']);
        $this->assertGreaterThan(3, $sum['life']);
        $this->assertGreaterThan(2, $sum['none']);
        $this->assertSame(80, $sum['good_standing'] + $sum['lapsed'] + $sum['none'], 'life counts inside good standing');

        // Every role signs in with the published password; the phone's "try the demo" (app=unitydesk) opens the association.
        foreach (array_keys(UnityDemo::roles()) as $key) {
            $this->assertStringEndsWith('@sampleassociation.example', UnityDemo::email($key));
            $res = $this->postJson('/api/v1/auth/login', ['email' => UnityDemo::email($key), 'password' => UnityDemo::password(), 'app' => 'unitydesk']);
            $this->assertSame(200, $res->status(), $key.': '.$res->getContent());
            $this->assertFalse((bool) $res->json('data.access.handoff'), $key);
        }
        $this->getJson('/api/v1/demo/session?app=unitydesk')->assertOk()->assertJsonCount(5, 'data.roles')->assertJsonPath('data.app', 'unitydesk');
        $chair = $this->postJson('/api/v1/demo/session', ['app' => 'unitydesk', 'role' => 'chair'])->assertOk()->assertJsonPath('data.app', 'unitydesk')->json('data.token');
        $this->getJson('/api/v1/unity/summary', ['Authorization' => 'Bearer '.$chair])->assertOk()->assertJsonPath('data.members', 80);
        $member = $this->postJson('/api/v1/demo/session', ['app' => 'unity', 'role' => 'member'])->assertOk()->json('data.token');
        $this->getJson('/api/v1/unity/me', ['Authorization' => 'Bearer '.$member])->assertOk()->assertJsonPath('data.name', 'Juliet Auma');
        $this->getJson('/api/v1/unity/members', ['Authorization' => 'Bearer '.$member])->assertForbidden();

        // Visitors cannot add to the team or change a demo sign-in; the words are the association's own.
        try {
            PublicDemo::guard($company, 'adding people');
            $this->fail('the demo association is locked');
        } catch (BusinessRuleException $e) {
            $this->assertSame('demo_locked', $e->errorCode());
            $this->assertStringContainsString('association', $e->getMessage());
            $this->assertStringNotContainsString('Budget Pro', $e->getMessage());
        }
        try {
            PublicDemo::guardAccount(UnityDemo::login('secretary'), 'changing the password');
            $this->fail('the demo sign-ins are locked');
        } catch (BusinessRuleException $e) {
            $this->assertSame('demo_locked', $e->errorCode());
        }

        // The hourly run heals; a rebuild replaces it, and the old association is gone.
        $this->assertSame('healed', app(UnityDemo::class)->run()['action']);
        $again = app(UnityDemo::class)->run(true);
        $this->assertSame('rebuilt', $again['action']);
        $this->assertFalse(DB::table('companies')->where('id', $cid)->exists());
        $this->assertSame(0, SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame(0, DB::table('unity_subscriptions')->where('company_id', $cid)->count());
    }
}

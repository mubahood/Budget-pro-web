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
        \Illuminate\Support\Facades\Storage::fake('public'); // the drawn memory pictures stay off the real disk
        $r = app(KinDemo::class)->run(true);
        $this->assertContains($r['action'], ['built', 'rebuilt']);
        $company = KinDemo::company();
        $this->assertNotNull($company);
        $cid = (int) $company->id;
        $this->assertSame(['kin', 'sacco'], $company->modules(), 'the purse is on');

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

        // Build 2: every age band, the sign-ins in the tree, contributions, news and the purse with a running loan.
        $h = ['Authorization' => 'Bearer '.$token];
        $this->assertNotNull($this->getJson('/api/v1/kin/tree', $h)->json('meta.me_id'));
        $stats = $this->getJson('/api/v1/kin/stats', $h)->assertOk()->json('data');
        foreach ($stats['age_bands'] as $band) {
            $this->assertGreaterThan(0, $band['count'], 'age band '.$band['key']);
        }
        $this->assertSame(3, $stats['dues']['active'], 'build 3 adds the introduction\'s contribution');
        $this->assertTrue($stats['announcement']['pinned']);
        $this->assertTrue($stats['purse']['enabled']);
        $this->assertGreaterThan(0, $stats['purse']['loans_out_minor']);
        $dues = collect($this->getJson('/api/v1/kin/dues', $h)->json('data.programmes'))->keyBy('name');
        $this->assertSame(6, count($this->getJson('/api/v1/kin/dues/'.$dues['Monthly family subscription']['id'], $h)->json('data.periods')));
        $this->assertSame($dues['Ancestral home renovation']['target_minor'] / 2, $dues['Ancestral home renovation']['collected_minor']);
        $this->assertGreaterThan(0, $dues['Monthly family subscription']['part_count']);
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('sacco:ledger-verify', ['--company' => $cid]));

        // Build 3: gatherings ahead and one held, memories with drawn pictures, and only purse people in the purse.
        $this->assertSame('meeting', $stats['next_event']['kind']);
        $ahead = $this->getJson('/api/v1/kin/events', $h)->assertOk()->json('data.events');
        $this->assertSame(['meeting', 'introduction'], array_column($ahead, 'kind'));
        $this->assertNotNull($ahead[1]['dues_programme_id']);
        $held = $this->getJson('/api/v1/kin/events?when=past', $h)->json('data.events');
        $this->assertSame('held', $held[0]['status']);
        $this->assertNotEmpty($this->getJson('/api/v1/kin/events/'.$held[0]['id'], $h)->json('data.event.minutes'));
        $this->assertCount(6, $this->getJson('/api/v1/kin/memories', $h)->json('data.memories'));
        $memoryIds = DB::table('kin_memories')->where('company_id', $cid)->pluck('id');
        $this->assertSame(4, DB::table('kin_memory_files')->whereIn('memory_id', $memoryIds)->count());
        $this->assertLessThan($people, SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('in_purse', 1)->count());

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
        $this->assertSame(0, DB::table('kin_memory_files')->whereIn('memory_id', $memoryIds)->count(), 'memory rows without a company_id go too');
        $this->assertSame('healed', app(KinDemo::class)->run()['action']);
    }
}

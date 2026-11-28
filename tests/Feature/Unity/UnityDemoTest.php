<?php

namespace Tests\Feature\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoMember;
use App\Models\UnityChapter;
use App\Services\Onboarding\PublicDemo;
use App\Services\Unity\Demo\UnityDemo;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/** The demo association: 300 invented members, seven chapters in three regions, a year of group life, one sign-in per role, locked. */
class UnityDemoTest extends ApiTestCase
{
    public function test_the_demo_association_is_built_signed_into_and_locked(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        \Illuminate\Support\Facades\Storage::fake('public'); // the photos: never the real disk, whose files/{id} folders belong to the local books
        $r = app(UnityDemo::class)->run(true);
        $this->assertContains($r['action'], ['built', 'rebuilt']);
        $company = UnityDemo::company();
        $this->assertNotNull($company);
        $cid = (int) $company->id;
        $this->assertSame(['unity'], $company->modules());
        $this->assertSame('Sample Association (Demo)', $company->name);

        $members = SaccoMember::withoutGlobalScopes()->where('company_id', $cid);
        $this->assertSame(300, (clone $members)->count());
        $this->assertSame(['chapter' => 7, 'region' => 3], UnityChapter::withoutGlobalScopes()->where('company_id', $cid)->selectRaw('kind, count(*) n')->groupBy('kind')->pluck('n', 'kind')->map(fn ($n) => (int) $n)->sortKeys()->all());
        $this->assertSame(7, UnityChapter::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'chapter')->whereNotNull('code')->whereNotNull('lead_member_id')->count());
        $this->assertSame(['Life', 'Ordinary annual', 'Student annual'], DB::table('unity_categories')->where('company_id', $cid)->orderBy('name')->pluck('name')->all());
        $this->assertSame(0, (clone $members)->where(fn ($q) => $q->whereNotNull('phone_e164')->orWhereNotNull('next_of_kin_phone_e164'))->count(), 'no made-up phone numbers');
        $status = (clone $members)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $this->assertSame(['applicant' => 8, 'deceased' => 5, 'exited' => 10, 'rejected' => 2, 'suspended' => 6], array_intersect_key($status, array_flip(['applicant', 'deceased', 'exited', 'rejected', 'suspended'])));
        $this->assertSame(269, $status['active'] + $status['dormant']);
        $this->assertTrue($status['dormant'] >= 15 && $status['dormant'] <= 60, 'some members set aside as dormant');
        $photos = (clone $members)->whereNotNull('photo_file_id')->count();
        $this->assertTrue($photos > 150 && $photos < 260, "most members have a photo, not all ({$photos})");
        $this->assertGreaterThan(10.0, (float) DB::table('sacco_members')->where('company_id', $cid)->whereNotNull('member_since')->selectRaw('TIMESTAMPDIFF(YEAR, MIN(member_since), CURDATE()) y')->value('y'));

        $sum = \App\Services\Unity\Memberships::summary($cid);
        $this->assertSame(283, $sum['members']);
        $this->assertGreaterThan(150, $sum['good_standing']);
        $this->assertGreaterThan(30, $sum['lapsed']);
        $this->assertGreaterThan(10, $sum['expiring_30_days']);
        $this->assertGreaterThan(5, $sum['life']);
        $this->assertGreaterThan(3, $sum['none']);
        $this->assertSame(283, $sum['good_standing'] + $sum['lapsed'] + $sum['none'], 'life counts inside good standing');

        // The home screen has a story: Mbarara attends best, Jinja improved most, people to follow up, this week's dates.
        $in = \App\Services\Unity\Insights::build($company, true);
        $this->assertSame('Mbarara', $in['health']['top_attending_chapter']['name']);
        $this->assertSame('Jinja', $in['health']['most_improved_chapter']['name']);
        $this->assertGreaterThan(15, $in['health']['most_improved_chapter']['change_pts']);
        $this->assertNotNull($in['health']['score']);
        foreach (['lapsed_60', 'missed_3', 'pledges_overdue', 'applicants', 'missing_photo', 'missing_next_of_kin'] as $k) {
            $this->assertGreaterThan(0, $in['attention'][$k], $k);
        }
        $this->assertGreaterThanOrEqual(5, count($in['this_week']['birthdays']));
        $this->assertGreaterThanOrEqual(5, count($in['this_week']['anniversaries']));
        $this->assertNotNull($in['this_week']['next_meeting']);
        $this->assertSame('poll', $in['this_week']['open_ballot']['kind']);
        $this->assertGreaterThan(0, $in['this_week']['open_ballot']['voted']);
        $this->assertCount(12, array_filter($in['growth'], fn ($g) => $g['joined'] > 0));
        $this->assertGreaterThan(0, $in['headline']['fund_balance_minor']);

        $count = fn (string $table, string $col) => DB::table($table)->where('company_id', $cid)->selectRaw("{$col} k, count(*) n")->groupBy($col)->pluck('n', 'k')->map(fn ($n) => (int) $n)->sortKeys()->all();
        $this->assertSame(['approved', 'paid', 'pending', 'rejected'], array_keys($count('unity_welfare_requests', 'status')));
        $this->assertSame(['election' => 'closed', 'poll' => 'open'], DB::table('unity_ballots')->where('company_id', $cid)->orderBy('kind')->pluck('status', 'kind')->all());
        $this->assertSame(['harambee', 'levy'], array_keys($count('unity_drives', 'kind')));
        $this->assertSame(['agm', 'event', 'meeting'], array_keys($count('unity_meetings', 'kind')));
        $this->assertGreaterThan(0, DB::table('unity_attendance')->where('company_id', $cid)->whereNotNull('ticket_no')->count(), 'dinner tickets');
        $this->assertSame(1, DB::table('unity_committees')->where('company_id', $cid)->whereNull('chapter_id')->count());
        $this->assertSame(['done', 'open'], array_keys($count('unity_resolutions', 'status')));
        $this->assertSame(4, DB::table('unity_announcements')->where('company_id', $cid)->count());

        // Every role signs in with the published password; the phone's "try the demo" (app=unitydesk) opens the association.
        foreach (array_keys(UnityDemo::roles()) as $key) {
            $this->assertStringEndsWith('@sampleassociation.example', UnityDemo::email($key));
            $res = $this->postJson('/api/v1/auth/login', ['email' => UnityDemo::email($key), 'password' => UnityDemo::password(), 'app' => 'unitydesk']);
            $this->assertSame(200, $res->status(), $key.': '.$res->getContent());
            $this->assertFalse((bool) $res->json('data.access.handoff'), $key);
        }
        $this->getJson('/api/v1/demo/session?app=unitydesk')->assertOk()->assertJsonCount(5, 'data.roles')->assertJsonPath('data.app', 'unitydesk');
        $chair = $this->postJson('/api/v1/demo/session', ['app' => 'unitydesk', 'role' => 'chair'])->assertOk()->assertJsonPath('data.app', 'unitydesk')->json('data.token');
        $this->getJson('/api/v1/unity/summary', ['Authorization' => 'Bearer '.$chair])->assertOk()->assertJsonPath('data.members', 283);
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

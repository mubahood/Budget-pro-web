<?php

namespace Tests\Feature\Unity;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Services\Unity\CardTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesUnity;
use Tests\Feature\Api\ApiTestCase;

/**
 * The five modules on a small known group (docs 12 and 13): home insights, the organisation tree, member actions and the
 * statement, drives, meetings, welfare, ballots (once, secret while open, eligibility, plan), the noticeboard and messages,
 * the public card check, and what each role may do. Every name is made up.
 */
class UnityModulesTest extends ApiTestCase
{
    use MakesUnity;

    private Company $co;

    /** @var array<string, string> */
    private array $head;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow('2026-06-15 10:00:00');
        [$this->co, , $token] = $this->unityAccount();
        $this->head = ['Authorization' => 'Bearer '.$token];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function as(string $role, ?string $email = null): array
    {
        return ['Authorization' => 'Bearer '.$this->unityStaff($this->co, $role, $email)->createToken('t')->plainTextToken];
    }

    private function send(string $path, array $data = [], ?array $h = null, int $status = 200)
    {
        return $this->postJson('/api/v1/unity/'.$path, $data, $h ?? $this->head)->assertStatus($status);
    }

    private function read(string $path, ?array $h = null)
    {
        return $this->getJson('/api/v1/unity/'.$path, $h ?? $this->head)->assertOk();
    }

    /**
     * Kampala chapter (in Central region): Amina (paid, teacher, birthday on the 17th, email amina@…), Brian (lapsed).
     * Gulu chapter (no region): Carol (paid). No chapter: Dan (applicant).
     *
     * @return array<string, int>
     */
    private function group(): array
    {
        $cat = (int) $this->send('categories', ['name' => 'Ordinary', 'fee_minor' => 50000, 'period_months' => 12], null, 201)->json('data.id');
        $region = (int) $this->send('chapters', ['name' => 'Central', 'kind' => 'region', 'code' => 'CEN'], null, 201)->json('data.id');
        $kla = (int) $this->send('chapters', ['name' => 'Kampala', 'code' => 'KLA', 'parent_id' => $region], null, 201)->json('data.id');
        $gulu = (int) $this->send('chapters', ['name' => 'Gulu', 'code' => 'GUL'], null, 201)->json('data.id');
        $m = fn (array $d) => (int) $this->send('members', $d + ['last_name' => 'Okello', 'category_id' => $cat], null, 201)->json('data.id');
        $amina = $m(['first_name' => 'Amina', 'gender' => 'female', 'date_of_birth' => '1990-06-17', 'occupation' => 'Teacher', 'district' => 'Wakiso',
            'email' => 'amina_'.uniqid().'@example.com', 'chapter_id' => $kla, 'member_since' => '2016-06-18', 'phone' => '0772000001']);
        $brian = $m(['first_name' => 'Brian', 'gender' => 'male', 'chapter_id' => $kla, 'email' => 'brian_'.uniqid().'@example.com']);
        $carol = $m(['first_name' => 'Carol', 'gender' => 'female', 'chapter_id' => $gulu, 'occupation' => 'teacher ']);
        $dan = $m(['first_name' => 'Dan', 'gender' => 'male', 'status' => 'applicant']);
        $this->send('subscriptions', ['member_id' => $amina, 'paid_minor' => 50000], null, 201);
        $this->send('subscriptions', ['member_id' => $carol, 'paid_minor' => 50000], null, 201);
        $this->send('subscriptions', ['member_id' => $brian, 'paid_minor' => 50000], null, 201);
        // Brian paid for March 2025 to February 2026, last year: lapsed now.
        DB::table('unity_subscriptions')->where('member_id', $brian)->update(['starts_on' => '2025-03-01', 'ends_on' => '2026-02-28', 'paid_on' => '2025-03-01']);
        \App\Services\Unity\Memberships::recompute((int) $this->co->id, $brian);

        return compact('cat', 'region', 'kla', 'gulu', 'amina', 'brian', 'carol', 'dan');
    }

    private function email(int $memberId): string
    {
        return (string) SaccoMember::withoutGlobalScopes()->findOrFail($memberId)->email;
    }

    public function test_insights_count_a_known_group_and_hide_money_without_finance(): void
    {
        $g = $this->group();
        $d = $this->read('insights')->json('data');

        $this->assertSame('2026-06-15', $d['as_of']);
        $this->assertSame(3, $d['headline']['active']);
        $this->assertSame(1, $d['status_split']['applicant']);
        $this->assertSame(100000, $d['headline']['collected_ytd_minor'], 'two payments this year; Brian\'s was last year');
        $this->assertSame(['female' => 2, 'male' => 1], collect($d['profile']['gender'])->where('count', '>', 0)->pluck('count', 'key')->sortKeys()->all());
        $this->assertSame(2, collect($d['profile']['occupation'])->firstWhere('key', 'teacher')['count'], 'grouped without case or spaces');
        $this->assertSame([$g['amina']], collect($d['this_week']['birthdays'])->pluck('id')->all());
        $this->assertSame(36, $d['this_week']['birthdays'][0]['turns']);
        $this->assertSame(10, $d['this_week']['anniversaries'][0]['years']);
        $this->assertSame(1, $d['attention']['applicants']);
        $kla = collect($d['chapters'])->firstWhere('id', $g['kla']);
        $this->assertSame(2, $kla['members']);
        $this->assertEquals(50.0, $kla['paid_up_pct']);
        $this->assertTrue($this->getJson('/api/v1/unity/insights', $this->head)->json('meta.access.finance'));

        $secretary = $this->read('insights', $this->as('unity_secretary'));
        $this->assertNull($secretary->json('data.headline.collected_ytd_minor'));
        $this->assertNull($secretary->json('data.headline.fund_balance_minor'));
        $this->assertFalse($secretary->json('meta.access.finance'));
        $this->assertSame(3, $secretary->json('data.headline.active'));
    }

    public function test_the_tree_runs_org_region_chapter_and_finds_people(): void
    {
        $g = $this->group();
        $this->send('committees', ['name' => 'Kampala committee', 'chapter_id' => $g['kla']], null, 201);
        $this->send('members/'.$g['dan'].'/approve');

        $org = $this->read('tree')->json('data');
        $this->assertSame('org', $org['node']['key']);
        $this->assertSame(['region:'.$g['region'], 'chapter:'.$g['gulu']], array_slice(collect($org['children'])->pluck('key')->all(), 0, 2));
        $this->assertContains('unassigned', collect($org['children'])->pluck('key')->all(), 'Dan has no chapter');
        $this->assertSame(2, collect($org['children'])->firstWhere('key', 'region:'.$g['region'])['count']);

        $region = $this->read('tree?node=region:'.$g['region'])->json('data.children');
        $this->assertSame('chapter:'.$g['kla'], $region[0]['key']);
        $chapter = collect($this->read('tree?node=chapter:'.$g['kla'])->json('data.children'));
        $this->assertSame('committee', $chapter->first()['kind']);
        $this->assertEqualsCanonicalizing(['member:'.$g['amina'], 'member:'.$g['brian']], $chapter->where('kind', 'member')->pluck('key')->all());
        $this->assertSame(1, $this->read('tree?node=chapter:'.$g['kla'].'&paid_up=1')->json('data.node.count'));

        $found = $this->read('tree/find?q=Amina')->json('data');
        $this->assertSame(['org', 'region:'.$g['region'], 'chapter:'.$g['kla']], $found[0]['path']);
        $this->assertCount(4, $this->read('tree/outline')->json('data'), 'a region, two chapters and a committee');
    }

    public function test_member_actions_leave_a_history_and_the_statement_adds_up(): void
    {
        $g = $this->group();
        $this->send('members/'.$g['dan'].'/approve');
        $this->assertSame('active', SaccoMember::withoutGlobalScopes()->find($g['dan'])->status);
        $this->send('members/'.$g['brian'].'/suspend', ['reason' => 'Unpaid levy']);
        $this->send('members/'.$g['brian'].'/reinstate');
        $this->send('members/'.$g['brian'].'/transfer', ['chapter_id' => $g['gulu']]);
        $this->send('members/'.$g['carol'].'/exit', ['reason' => 'Moved abroad']);
        $this->send('members/'.$g['carol'].'/exit', ['reason' => 'Twice'], null, 422);
        $this->send('members/'.$g['dan'].'/reject', ['reason' => 'Too late'], null, 422);

        $kinds = collect($this->read('members/'.$g['brian'].'/history')->json('data'))->pluck('kind')->all();
        $this->assertSame(['transferred', 'reinstated', 'suspended'], array_slice($kinds, 0, 3), 'newest first');
        $this->assertSame($g['gulu'], (int) SaccoMember::withoutGlobalScopes()->find($g['brian'])->chapter_id);

        $s = $this->read('members/'.$g['amina'].'/statement')->json('data');
        $this->assertSame(50000, $s['totals']['dues_paid_minor']);
        $this->assertCount(1, $s['subscriptions']);

        $amina = $this->as('unity_member', $this->email($g['amina']));
        $this->assertSame(50000, $this->read('me/statement', $amina)->json('data.totals.dues_paid_minor'), 'own money is shown');
        $this->getJson('/api/v1/unity/members/'.$g['brian'].'/statement', $amina)->assertForbidden();
        $this->assertNull($this->read('members/'.$g['amina'].'/statement', $this->as('unity_secretary'))->json('data.totals.dues_paid_minor'), 'no money without finance');
    }

    public function test_drives_levy_applies_once_and_harambee_takes_more_than_pledged(): void
    {
        $g = $this->group();
        $levy = (int) $this->send('drives', ['kind' => 'levy', 'title' => 'Hall levy', 'per_member_minor' => 20000, 'due_on' => '2026-07-01'], null, 201)->json('data.id');
        $this->send("drives/{$levy}/apply");
        $this->send("drives/{$levy}/apply");
        $this->assertSame(3, DB::table('unity_pledges')->where('drive_id', $levy)->where('is_deleted', 0)->count(), 'current members once each');
        $this->assertNotEmpty($this->send("drives/{$levy}/payments", ['member_id' => $g['amina'], 'amount_minor' => 20000, 'method' => 'cash'], null, 201)->json('data.receipt_no'));
        $this->send("drives/{$levy}/payments", ['member_id' => $g['brian'], 'amount_minor' => 30000, 'method' => 'cash'], null, 422);

        $fund = (int) $this->send('drives', ['kind' => 'harambee', 'title' => 'Bursary', 'target_minor' => 1000000], null, 201)->json('data.id');
        $this->send("drives/{$fund}/apply", [], null, 422);
        $this->send("drives/{$fund}/pledges", ['member_id' => $g['carol'], 'pledged_minor' => 100000], null, 201);
        $this->send("drives/{$fund}/payments", ['member_id' => $g['carol'], 'amount_minor' => 150000, 'method' => 'mobile_money'], null, 201);
        $d = $this->read("drives/{$fund}")->json('data.totals');
        $this->assertSame(150000, $d['pledged_minor']);
        $this->assertSame(150000, $d['paid_minor']);
        $this->assertEquals(15.0, $d['progress_pct']);
        $t = $this->read("drives/{$levy}")->json('data.totals');
        $this->assertSame([60000, 20000], [$t['pledged_minor'], $t['paid_minor']]);
        $this->postJson("/api/v1/unity/drives/{$fund}/payments", ['member_id' => $g['carol'], 'amount_minor' => 1, 'method' => 'cash'], $this->as('unity_secretary'))->assertForbidden();
    }

    public function test_meetings_take_attendance_rsvp_tickets_and_card_checkin(): void
    {
        $g = $this->group();
        $mt = (int) $this->send('meetings', ['kind' => 'meeting', 'title' => 'June meeting', 'chapter_id' => $g['kla'], 'starts_at' => '2026-06-14 17:00'], null, 201)->json('data.id');
        $this->send("meetings/{$mt}/attendance", ['present' => [$g['amina']], 'apology' => [$g['brian']]]);
        $show = $this->read("meetings/{$mt}")->json('data');
        $this->assertSame([2, 1, 1], [$show['expected'], $show['present'], $show['apologies']]);
        $this->send("meetings/{$mt}/attendance", ['present' => [$g['carol']]], null, 422);

        $event = (int) $this->send('meetings', ['kind' => 'event', 'title' => 'Dinner', 'starts_at' => '2026-07-01T18:00:00+03:00', 'rsvp_open' => true, 'capacity' => 50], null, 201)->json('data.id');
        $this->assertSame('2026-07-01T15:00:00+00:00', Carbon::parse($this->read("meetings/{$event}")->json('data.starts_at'))->utc()->toIso8601String());
        $amina = $this->as('unity_member', $this->email($g['amina']));
        $ticket = $this->send("meetings/{$event}/rsvp", ['answer' => 'yes'], $amina)->json('data.ticket_no');
        $this->assertSame('E'.$event.'-0001', $ticket);
        $this->send("meetings/{$event}/rsvp", ['answer' => 'yes', 'member_id' => $g['carol']], $amina, 403);

        $token = CardTokens::token(SaccoMember::withoutGlobalScopes()->findOrFail($g['amina']));
        $this->assertSame($g['amina'], $this->send("meetings/{$event}/checkin", ['token' => $token])->json('data.member.id'));
        $this->send("meetings/{$event}/checkin", ['token' => $token]);
        $this->assertSame(1, $this->read("meetings/{$event}")->json('data.present'), 'safe to repeat');
        $this->send("meetings/{$event}/checkin", ['token' => str_repeat('0', 48)], null, 404);
    }

    public function test_welfare_pays_only_what_the_fund_holds_and_a_member_asks_only_for_themselves(): void
    {
        $g = $this->group();
        $this->assertNotEmpty($this->send('welfare/entries', ['direction' => 'in', 'kind' => 'contribution', 'amount_minor' => 100000, 'member_id' => $g['amina'], 'method' => 'cash'], null, 201)->json('data.receipt_no'));
        $amina = $this->as('unity_member', $this->email($g['amina']));
        $mine = (int) $this->send('welfare/requests', ['kind' => 'sickness', 'details' => 'Hospital bill', 'requested_minor' => 150000], $amina, 201)->json('data.id');
        $this->send('welfare/requests', ['member_id' => $g['carol'], 'kind' => 'sickness', 'details' => 'For Carol', 'requested_minor' => 1000], $amina, 403);
        $this->assertSame([$mine], collect($this->read('welfare/requests', $amina)->json('data'))->pluck('id')->all());

        $this->send("welfare/requests/{$mine}/approve", ['approved_minor' => 150000]);
        $this->send("welfare/requests/{$mine}/pay", ['method' => 'cash'], null, 409);
        $this->send('welfare/entries', ['direction' => 'in', 'kind' => 'contribution', 'amount_minor' => 60000, 'member_id' => $g['carol'], 'method' => 'cash'], null, 201);
        $this->send("welfare/requests/{$mine}/pay", ['method' => 'cash']);
        $s = $this->read('welfare/summary')->json('data');
        $this->assertSame([10000, 160000, 150000], [$s['balance_minor'], $s['in_ytd_minor'], $s['out_ytd_minor']]);
        $this->assertNull($this->read('welfare/summary', $this->as('unity_secretary'))->json('data.balance_minor'));
        $this->send('welfare/entries', ['direction' => 'in', 'kind' => 'contribution', 'amount_minor' => 1], $this->as('unity_secretary'), 403);
    }

    public function test_a_ballot_takes_one_secret_vote_from_eligible_members_and_elections_need_the_plan(): void
    {
        $g = $this->group();
        $b = $this->send('ballots', ['kind' => 'poll', 'title' => 'AGM venue', 'eligibility' => 'paid_up', 'closes_at' => '2026-06-20 17:00',
            'questions' => [['title' => 'Where?', 'options' => [['label' => 'Hall'], ['label' => 'Hotel']]]]], null, 201)->json('data');
        $q = $b['questions'][0]['id'];
        [$hall, $hotel] = collect($b['questions'][0]['options'])->pluck('id')->all();
        $amina = $this->as('unity_member', $this->email($g['amina']));
        $brian = $this->as('unity_member', $this->email($g['brian']));
        $this->assertSame([], $this->read('ballots', $amina)->json('data'), 'a draft is the officers\' own');
        $this->send("ballots/{$b['id']}/vote", ['answers' => [['question_id' => $q, 'option_id' => $hall]]], $amina, 404);
        $this->send("ballots/{$b['id']}/open");

        $this->send("ballots/{$b['id']}/vote", ['answers' => [['question_id' => $q, 'option_id' => $hall]]], $brian, 409)->assertJsonPath('errors.code', 'not_eligible');
        $voted = $this->send("ballots/{$b['id']}/vote", ['answers' => [['question_id' => $q, 'option_id' => $hall]]], $amina)->json('data');
        $this->assertSame([$hall], $voted['my_vote']);
        $this->assertFalse($voted['can_vote']);
        $this->assertNull($voted['results'], 'no tally for members while open');
        $this->send("ballots/{$b['id']}/vote", ['answers' => [['question_id' => $q, 'option_id' => $hotel]]], $amina, 409)->assertJsonPath('errors.code', 'already_voted');

        $officer = $this->read("ballots/{$b['id']}")->json('data');
        $this->assertSame(['voted' => 1, 'eligible' => 2, 'pct' => 50], $officer['results']['turnout']);
        $this->assertSame([], $officer['my_vote']);
        $this->assertStringNotContainsString('"member_id":'.$g['amina'], json_encode($officer['results']), 'the tally never names a voter');
        $this->send("ballots/{$b['id']}/close");
        $this->assertSame(1, $this->read("ballots/{$b['id']}", $brian)->json('data.results.questions.0.options.0.votes'));
        $this->send("ballots/{$b['id']}/vote", ['answers' => [['question_id' => $q, 'option_id' => $hotel]]], $brian, 409);

        $election = ['kind' => 'election', 'title' => 'Chair', 'eligibility' => 'current',
            'questions' => [['title' => 'Chairperson', 'options' => [['label' => 'Amina', 'member_id' => $g['amina']], ['label' => 'Carol', 'member_id' => $g['carol']]]]]];
        $this->send('ballots', $election, null, 201);
        DB::table('subscriptions')->where('company_id', $this->co->id)->update(['plan_id' => DB::table('plans')->where('slug', 'unitydesk-group')->value('id')]);
        $this->send('ballots', $election, null, 402);
        $this->send('ballots', ['kind' => 'poll'] + $election, null, 201);
    }

    public function test_the_noticeboard_reaches_its_audience_and_messages_count_recipients(): void
    {
        $g = $this->group();
        $this->send('announcements', ['title' => 'AGM', 'body' => 'Everyone please come.', 'audience' => 'all', 'pinned' => true], null, 201);
        $this->send('announcements', ['title' => 'Gulu only', 'body' => 'Chapter dinner.', 'audience' => 'chapter', 'audience_id' => $g['gulu']], null, 201);
        $amina = $this->as('unity_member', $this->email($g['amina']));
        $this->assertSame(['AGM'], collect($this->read('announcements', $amina)->json('data'))->pluck('title')->all());
        $this->assertCount(2, $this->read('announcements')->json('data'));
        $this->send('announcements', ['title' => 'No', 'body' => 'No.', 'audience' => 'all'], $amina, 403);

        $sent = $this->send('messages', ['body' => 'Dues are due.', 'channel' => 'notice', 'filter' => ['chapter_id' => $g['kla']]])->json('data');
        $this->assertSame(2, $sent['recipients']);
        $this->send('messages', ['body' => 'Hi', 'channel' => 'notice'], $this->as('unity_treasurer'), 403);
    }

    public function test_a_card_verifies_publicly_and_says_no_more_than_it_should(): void
    {
        $g = $this->group();
        $token = CardTokens::token(SaccoMember::withoutGlobalScopes()->findOrFail($g['amina']));
        $this->getJson('/api/v1/public/unity/verify/'.$token)->assertOk()->assertJsonPath('data.state', 'paid_up')->assertJsonPath('data.chapter_code', 'KLA')
            ->assertJsonMissingPath('data.phone')->assertJsonMissingPath('data.date_of_birth');
        $this->getJson('/api/v1/public/unity/verify/'.CardTokens::token(SaccoMember::withoutGlobalScopes()->findOrFail($g['brian'])))->assertJsonPath('data.state', 'lapsed');
        $this->getJson('/api/v1/public/unity/verify/'.substr($token, 0, 40).'00000000')->assertNotFound()->assertJsonPath('errors.code', 'not_found');
        $this->assertSame($token, $this->read('me/card', $this->as('unity_member', $this->email($g['amina'])))->json('data.token'));
    }

    public function test_each_role_reaches_only_its_modules_and_a_member_only_themselves(): void
    {
        $g = $this->group();
        $mt = ['kind' => 'meeting', 'title' => 'T', 'starts_at' => '2026-06-20 10:00'];
        $poll = ['kind' => 'poll', 'title' => 'P', 'eligibility' => 'current', 'questions' => [['title' => 'Q', 'options' => [['label' => 'A'], ['label' => 'B']]]]];
        $cases = [
            'unity_secretary' => ['meetings' => 201, 'ballots' => 201, 'committees' => 201, 'drives' => 403, 'welfare/entries' => 403],
            'unity_treasurer' => ['meetings' => 403, 'ballots' => 403, 'committees' => 403, 'drives' => 201, 'welfare/entries' => 201],
            'unity_chapter_lead' => ['meetings' => 201, 'ballots' => 403, 'committees' => 403, 'drives' => 403, 'welfare/entries' => 403],
            'unity_member' => ['meetings' => 403, 'ballots' => 403, 'committees' => 403, 'drives' => 403, 'welfare/entries' => 403],
        ];
        $bodies = ['meetings' => $mt, 'ballots' => $poll, 'committees' => ['name' => 'C'.uniqid()], 'drives' => ['kind' => 'harambee', 'title' => 'D'],
            'welfare/entries' => ['direction' => 'in', 'kind' => 'contribution', 'amount_minor' => 1000, 'method' => 'cash']];
        foreach ($cases as $role => $want) {
            $h = $this->as($role);
            foreach ($want as $path => $status) {
                $this->postJson('/api/v1/unity/'.$path, $bodies[$path], $h)->assertStatus($status, $role.' '.$path);
            }
        }

        $amina = $this->as('unity_member', $this->email($g['amina']));
        foreach (['insights', 'tree', 'members', 'dues/summary', 'drives', 'welfare/summary', 'committees', 'members/'.$g['carol'].'/card', 'resolutions'] as $path) {
            $this->getJson('/api/v1/unity/'.$path, $amina)->assertForbidden();
        }
        foreach (['access', 'me', 'me/card', 'me/statement', 'announcements', 'ballots', 'meetings'] as $path) {
            $this->read($path, $amina);
        }
        $this->assertSame($g['amina'], $this->read('access', $amina)->json('data.member_id'));
        $this->assertFalse($this->read('access', $amina)->json('data.members'));
    }
}

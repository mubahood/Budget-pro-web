<?php

namespace Tests\Feature\Unity;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesUnity;
use Tests\Feature\Api\ApiTestCase;

/** /api/v1/unity/…: officers by role, a member sees only themselves, another association sees nothing, sync. Every name is made up. */
class UnityApiTest extends ApiTestCase
{
    use MakesUnity;

    private Company $co;

    /** @var array<string, string> */
    private array $head;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function category(string $name = 'Ordinary annual', int $fee = 50000, int $months = 12): int
    {
        return (int) $this->postJson('/api/v1/unity/categories', ['name' => $name, 'fee_minor' => $fee, 'period_months' => $months], $this->head)->assertCreated()->json('data.id');
    }

    private function member(array $data, ?array $h = null): int
    {
        return (int) $this->postJson('/api/v1/unity/members', $data, $h ?? $this->head)->assertCreated()->json('data.id');
    }

    public function test_the_chair_runs_members_categories_subscriptions_chapters_and_the_summary(): void
    {
        $annual = $this->category();
        $life = $this->category('Life', 500000, 0);
        $chapter = (int) $this->postJson('/api/v1/unity/chapters', ['name' => 'Central', 'location' => 'Sample Town'], $this->head)->assertCreated()->json('data.id');

        $amina = $this->member(['first_name' => 'Amina', 'last_name' => 'Sample', 'phone' => '0772000001', 'category_id' => $annual, 'chapter_id' => $chapter]);
        $bena = $this->member(['first_name' => 'Bena', 'last_name' => 'Sample', 'category_id' => $annual]);
        $cissy = $this->member(['first_name' => 'Cissy', 'last_name' => 'Sample']);
        $this->getJson("/api/v1/unity/members/{$amina}", $this->head)->assertOk()->assertJsonPath('data.standing', 'none')->assertJsonPath('data.phone', '+256772000001');

        // Amina pays in full (her category's fee); Bena pays part; Cissy is a life member.
        $s = $this->postJson('/api/v1/unity/subscriptions', ['member_id' => $amina, 'paid_minor' => 50000, 'method' => 'mobile_money'], $this->head)->assertCreated()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.ends_on', '2027-06-14')->json('data');
        $this->assertMatchesRegularExpression('/^RCT-2026-/', $s['receipt_no']);
        $part = (int) $this->postJson('/api/v1/unity/subscriptions', ['member_id' => $bena, 'paid_minor' => 20000], $this->head)->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.balance_minor', 30000)->json('data.id');
        $this->postJson('/api/v1/unity/subscriptions', ['member_id' => $cissy], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'validation');
        $this->postJson('/api/v1/unity/subscriptions', ['member_id' => $cissy, 'category_id' => $life, 'paid_minor' => 500000], $this->head)->assertCreated();

        $this->getJson('/api/v1/unity/members?standing=good', $this->head)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/unity/members?standing=none', $this->head)->assertOk()->assertJsonPath('data.0.id', $bena);
        $this->getJson("/api/v1/unity/members?chapter_id={$chapter}", $this->head)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/unity/members?q=Cis', $this->head)->assertOk()->assertJsonPath('data.0.standing', 'life');

        // The rest of Bena's fee comes in: paid. Then cancelled, it no longer counts, and stays cancelled.
        $this->patchJson("/api/v1/unity/subscriptions/{$part}", ['paid_minor' => 50000], $this->head)->assertOk()->assertJsonPath('data.status', 'paid');
        $this->getJson("/api/v1/unity/members/{$bena}", $this->head)->assertJsonPath('data.standing', 'good')->assertJsonCount(1, 'data.subscriptions');
        $this->postJson("/api/v1/unity/subscriptions/{$part}/cancel", ['reason' => 'Entered twice'], $this->head)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson("/api/v1/unity/members/{$bena}", $this->head)->assertJsonPath('data.standing', 'none');
        $this->patchJson("/api/v1/unity/subscriptions/{$part}", ['status' => 'paid'], $this->head)->assertStatus(409);
        $this->deleteJson("/api/v1/unity/subscriptions/{$part}", [], $this->head)->assertStatus(405);

        $this->getJson('/api/v1/unity/summary', $this->head)->assertOk()
            ->assertJsonPath('data.members', 3)->assertJsonPath('data.good_standing', 2)->assertJsonPath('data.lapsed', 0)
            ->assertJsonPath('data.collected_this_year_minor', 550000)->assertJsonPath('data.currency', 'UGX');

        $this->deleteJson("/api/v1/unity/categories/{$annual}", [], $this->head)->assertStatus(409)->assertJsonPath('errors.code', 'category_in_use');
        $this->deleteJson("/api/v1/unity/chapters/{$chapter}", [], $this->head)->assertStatus(409)->assertJsonPath('errors.code', 'chapter_in_use');
        $this->getJson('/api/v1/unity/categories', $this->head)->assertOk()->assertJsonFragment(['id' => $annual, 'members' => 2]);
        $this->assertSame(405, $this->deleteJson("/api/v1/unity/members/{$amina}", [], $this->head)->status(), 'a member is never deleted');
    }

    public function test_each_role_does_only_its_part(): void
    {
        $annual = $this->category();
        $secretary = $this->as('unity_secretary');
        $treasurer = $this->as('unity_treasurer');
        $lead = $this->as('unity_chapter_lead');

        $m = $this->member(['first_name' => 'Dora', 'category_id' => $annual], $secretary);
        $this->member(['first_name' => 'Esther'], $lead);
        $this->postJson('/api/v1/unity/members', ['first_name' => 'No'], $treasurer)->assertForbidden();

        $this->postJson('/api/v1/unity/subscriptions', ['member_id' => $m, 'paid_minor' => 50000], $treasurer)->assertCreated();
        $this->postJson('/api/v1/unity/subscriptions', ['member_id' => $m, 'paid_minor' => 50000], $secretary)->assertForbidden();
        $this->postJson('/api/v1/unity/categories', ['name' => 'Student', 'fee_minor' => 10000, 'period_months' => 12], $lead)->assertForbidden();

        $this->postJson('/api/v1/unity/chapters', ['name' => 'North'], $secretary)->assertCreated();
        $this->postJson('/api/v1/unity/chapters', ['name' => 'South'], $treasurer)->assertForbidden();
        foreach ([$secretary, $treasurer, $lead] as $h) {
            $this->getJson('/api/v1/unity/members', $h)->assertOk();
            $this->getJson('/api/v1/unity/summary', $h)->assertOk();
        }
    }

    public function test_a_member_reads_only_their_own_record(): void
    {
        $annual = $this->category();
        $email = 'fatuma_'.uniqid().'@example.com';
        $mine = $this->member(['first_name' => 'Fatuma', 'email' => $email, 'category_id' => $annual]);
        $this->member(['first_name' => 'Grace']);
        $this->postJson('/api/v1/unity/subscriptions', ['member_id' => $mine, 'paid_minor' => 50000], $this->head)->assertCreated();

        $member = $this->as('unity_member', $email);
        $this->getJson('/api/v1/unity/me', $member)->assertOk()->assertJsonPath('data.id', $mine)->assertJsonPath('data.standing', 'good')->assertJsonCount(1, 'data.subscriptions');
        foreach (['members', "members/{$mine}", 'summary', 'categories', 'subscriptions', 'chapters'] as $path) {
            $this->getJson('/api/v1/unity/'.$path, $member)->assertForbidden();
        }
        $this->patchJson("/api/v1/unity/members/{$mine}", ['first_name' => 'Changed'], $member)->assertForbidden();
        $this->getJson('/api/v1/unity/me', $this->as('unity_member'))->assertOk()->assertJsonPath('data', null);
    }

    public function test_another_association_and_other_apps_see_nothing(): void
    {
        $annual = $this->category();
        $m = $this->member(['first_name' => 'Hope', 'category_id' => $annual]);
        [, , $otherToken] = $this->unityAccount(['email' => 'other_'.uniqid().'@example.com']);
        $other = ['Authorization' => 'Bearer '.$otherToken];
        $this->getJson("/api/v1/unity/members/{$m}", $other)->assertNotFound();
        $this->getJson('/api/v1/unity/members', $other)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/unity/subscriptions', ['member_id' => $m, 'category_id' => $annual, 'paid_minor' => 50000], $other)->assertNotFound();
        $this->postJson('/api/v1/unity/members', ['first_name' => 'X', 'category_id' => $annual], $other)->assertStatus(422);

        $shop = $this->registerTenant(['modules' => ['shop']]);
        $this->getJson('/api/v1/unity/members', ['Authorization' => 'Bearer '.$shop['token']])->assertForbidden();
    }

    public function test_sync_carries_an_association_and_refuses_unity_rows_from_any_other_account(): void
    {
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $this->head)->assertOk();
        [$cat, $mem, $sub] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $op = fn (string $table, string $uuid, array $data) => ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid, 'action' => 'insert',
            'client_updated_at' => SyncSequence::nowMs(), 'version' => 0, 'data' => $data];
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => [
            $op('unity_categories', $cat, ['name' => 'Ordinary annual', 'fee_minor' => 50000, 'period_months' => 12]),
            $op('sacco_members', $mem, ['first_name' => 'Ivy', 'last_name' => 'Sample', 'category_uuid' => $cat, 'member_since' => '2020-01-01', 'standing' => 'life']),
            $op('unity_subscriptions', $sub, ['member_uuid' => $mem, 'category_uuid' => $cat, 'starts_on' => '2026-01-01', 'amount_minor' => 50000, 'paid_minor' => 50000, 'status' => 'pending']),
        ]]]], $this->head + ['X-Device-Id' => $device])->assertOk();
        foreach ($r->json('data.results') as $res) {
            $this->assertSame('applied', $res['status'], json_encode($res));
        }
        $m = SaccoMember::withoutGlobalScopes()->where('uuid', $mem)->firstOrFail();
        $this->assertSame('2020-01-01', $m->member_since->toDateString());
        $this->assertSame('good', $m->standing, 'standing is the server\'s, never the phone\'s');
        $this->assertSame('2026-12-31', $m->membership_ends_on->toDateString());
        $this->assertSame('paid', DB::table('unity_subscriptions')->where('uuid', $sub)->value('status'));

        // A treasurer's phone may not add members; a secretary's may not record money.
        $treasurer = $this->as('unity_treasurer');
        $this->postJson('/api/v1/devices/register', ['device_id' => $d2 = (string) Str::uuid()], $treasurer)->assertOk();
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => [$op('sacco_members', (string) Str::uuid(), ['first_name' => 'No'])]]]],
            $treasurer + ['X-Device-Id' => $d2])->assertOk();
        $this->assertSame('forbidden', $r->json('data.results.0.ops.0.code'));

        foreach (['sacco', 'kin', 'shop'] as $module) {
            $other = $this->registerTenant(['modules' => [$module]]);
            $h = ['Authorization' => 'Bearer '.$other['token']];
            $this->postJson('/api/v1/devices/register', ['device_id' => $d = (string) Str::uuid()], $h)->assertOk();
            foreach (['unity_categories' => ['name' => 'X', 'fee_minor' => 0, 'period_months' => 12], 'unity_chapters' => ['name' => 'X'], 'unity_subscriptions' => ['status' => 'waived']] as $table => $data) {
                $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => [$op($table, (string) Str::uuid(), $data)]]]],
                    $h + ['X-Device-Id' => $d])->assertOk();
                $this->assertSame('rejected', $r->json('data.results.0.status'), "{$module} {$table}");
                $this->assertSame(0, DB::table($table)->where('company_id', $other['company_id'])->count());
            }
        }
        $this->assertSame(['exclude'], \App\Support\Rules\SaccoMemberRules::unitySyncRules((int) $this->registerTenant(['modules' => ['sacco']])['company_id'])['member_since']);
    }
}

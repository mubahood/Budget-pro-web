<?php

namespace Tests\Feature\Api;

use App\Models\BudgetProgramMember;
use App\Services\Team\Permissions;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Committee roles per event (roadmap 02 §6): chair and treasurer record payments, secretary and member record
 * pledges, viewer reads; the owner and manage_budget keep full access; enforced on the API and on synced ops;
 * invitations by phone go through the team invite and land on the committee on acceptance.
 */
class CommitteeRolesTest extends ApiTestCase
{
    private array $owner;

    private array $oh;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::flush();
        $this->owner = $this->registerTenant();
        $this->oh = $this->auth($this->owner['token']);
    }

    /** A team member with the account role viewer (no manage_budget): only their committee role counts. */
    private function teammate(string $phone, string $accountRole = 'viewer'): array
    {
        $link = $this->postJson('/api/v1/team/invites', ['role' => $accountRole, 'phone' => $phone, 'name' => 'Helper'], $this->oh)->assertStatus(201)->json('data.link');

        return $this->accept(basename($link));
    }

    private function accept(string $token): array
    {
        $r = $this->postJson("/api/v1/invites/{$token}/accept", ['first_name' => 'Helper', 'last_name' => uniqid(), 'password' => 'secret123'])->assertStatus(201);
        Permissions::flush();

        return ['user_id' => (int) $r->json('data.user.id'), 'h' => $this->auth($r->json('data.token')), 'token' => $r->json('data.token')];
    }

    private function program(): int
    {
        return (int) $this->postJson('/api/v1/budget-programs', ['name' => 'Kwanjula '.uniqid()], $this->oh)->assertStatus(201)->json('data.id');
    }

    private function seat(int $program, int $userId, string $role): int
    {
        return (int) $this->postJson("/api/v1/budget-programs/{$program}/members", ['user_id' => $userId, 'role' => $role], $this->oh)->assertStatus(201)->json('data.id');
    }

    private function pledge(int $program, array $h, array $extra = [])
    {
        return $this->postJson('/api/v1/contribution-records', $extra + ['budget_program_id' => $program, 'name' => 'P '.uniqid(), 'amount' => 50000], $h);
    }

    public function test_each_committee_role_does_what_it_may_and_no_more(): void
    {
        $program = $this->program();
        $other = $this->program();
        $treasurer = $this->teammate('0772500001');
        $secretary = $this->teammate('0772500002');
        $viewer = $this->teammate('0772500003');
        $outsider = $this->teammate('0772500004');
        $this->seat($program, $treasurer['user_id'], 'treasurer');
        $this->seat($program, $secretary['user_id'], 'secretary');
        $this->seat($program, $viewer['user_id'], 'viewer');
        $this->seat($other, $outsider['user_id'], 'chair'); // chair elsewhere: nothing here

        // Pledges: secretary yes, treasurer yes, viewer and outsider no (the owner always).
        $pledgeId = (int) $this->pledge($program, $secretary['h'])->assertStatus(201)->json('data.id');
        $this->pledge($program, $treasurer['h'])->assertStatus(201);
        $this->pledge($program, $viewer['h'])->assertStatus(403)->assertJsonPath('errors.permission', 'record_pledges');
        $this->pledge($program, $outsider['h'])->assertStatus(403);
        $this->putJson("/api/v1/contribution-records/{$pledgeId}", ['amount' => 60000], $secretary['h'])->assertOk();

        // Payments: treasurer yes, secretary no (not even through the old paid_amount field).
        $this->postJson("/api/v1/contribution-records/{$pledgeId}/payments", ['amount' => 1000], $secretary['h'])->assertStatus(403)->assertJsonPath('errors.permission', 'record_payments');
        $this->putJson("/api/v1/contribution-records/{$pledgeId}", ['paid_amount' => 5000], $secretary['h'])->assertStatus(403)->assertJsonPath('errors.permission', 'record_payments');
        $this->pledge($program, $secretary['h'], ['fully_paid' => 'Yes'])->assertStatus(403);
        $payId = $this->postJson("/api/v1/contribution-records/{$pledgeId}/payments", ['amount' => 1000], $treasurer['h'])->assertStatus(201)->json('data.payment.id');
        $this->postJson("/api/v1/contribution-payments/{$payId}/reverse", ['reason' => 'Wrong person'], $secretary['h'])->assertStatus(403);
        $this->postJson("/api/v1/contribution-payments/{$payId}/reverse", ['reason' => 'Wrong person'], $treasurer['h'])->assertStatus(201);

        // Everyone on the team reads.
        $this->getJson("/api/v1/contribution-records/{$pledgeId}/payments", $viewer['h'])->assertOk()->assertJsonPath('data.abilities', []);
        $this->getJson("/api/v1/budget-programs/{$program}/members", $viewer['h'])->assertOk()->assertJsonPath('data.my_role', 'viewer');

        // Event, category and item edits stay with manage_budget.
        $this->putJson("/api/v1/budget-programs/{$program}", ['name' => 'Renamed'], $treasurer['h'])->assertStatus(403);
    }

    public function test_the_chair_runs_the_committee_and_others_cannot(): void
    {
        $program = $this->program();
        $chair = $this->teammate('0772500011');
        $member = $this->teammate('0772500012');
        $later = $this->teammate('0772500013');
        $this->seat($program, $chair['user_id'], 'chair');
        $memberSeat = $this->seat($program, $member['user_id'], 'member');

        $this->postJson("/api/v1/budget-programs/{$program}/members", ['user_id' => $later['user_id'], 'role' => 'treasurer'], $member['h'])->assertStatus(403)->assertJsonPath('errors.permission', 'manage_committee');
        $seat = $this->postJson("/api/v1/budget-programs/{$program}/members", ['user_id' => $later['user_id'], 'role' => 'treasurer'], $chair['h'])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/budget-programs/{$program}/members", ['user_id' => $later['user_id'], 'role' => 'member'], $chair['h'])->assertStatus(422)->assertJsonPath('errors.code', 'already_on_committee');
        $this->patchJson("/api/v1/budget-programs/{$program}/members/{$memberSeat}", ['role' => 'secretary'], $chair['h'])->assertOk()->assertJsonPath('data.role', 'secretary');
        $this->patchJson("/api/v1/budget-programs/{$program}/members/{$memberSeat}", ['role' => 'boss'], $chair['h'])->assertStatus(422);
        $this->deleteJson("/api/v1/budget-programs/{$program}/members/{$seat}", [], $member['h'])->assertStatus(403);
        $this->deleteJson("/api/v1/budget-programs/{$program}/members/{$seat}", [], $chair['h'])->assertOk();
        $this->assertSame(1, (int) BudgetProgramMember::withoutGlobalScopes()->where('id', $seat)->value('is_deleted'), 'a tombstone, so phones remove it');
        // Removed people can be added again.
        $this->postJson("/api/v1/budget-programs/{$program}/members", ['user_id' => $later['user_id'], 'role' => 'viewer'], $chair['h'])->assertStatus(201);

        // A chair cannot invite new people into the account (manage_team).
        $this->postJson("/api/v1/budget-programs/{$program}/members", ['phone' => '0772500099', 'role' => 'member'], $chair['h'])->assertStatus(403)->assertJsonPath('errors.permission', 'manage_team');

        $list = $this->getJson("/api/v1/budget-programs/{$program}/members", $this->oh)->assertOk()->json('data');
        $this->assertSame('chair', $list['members'][0]['role'], 'chair listed first');
        $this->assertContains('manage_committee', $list['abilities'], 'the owner has full access');
    }

    public function test_people_outside_the_team_or_company_cannot_be_seated(): void
    {
        $program = $this->program();
        $stranger = $this->registerTenant();
        $this->postJson("/api/v1/budget-programs/{$program}/members", ['user_id' => $stranger['user_id'], 'role' => 'member'], $this->oh)->assertStatus(422);
        $this->getJson("/api/v1/budget-programs/{$program}/members", $this->auth($stranger['token']))->assertNotFound();
        $this->postJson("/api/v1/budget-programs/{$program}/members", ['user_id' => $stranger['user_id'], 'role' => 'chair'], $this->auth($stranger['token']))->assertNotFound();
    }

    public function test_invite_by_phone_joins_the_team_then_the_committee(): void
    {
        $program = $this->program();
        $r = $this->postJson("/api/v1/budget-programs/{$program}/members", ['phone' => '0772 600 123', 'name' => 'Cousin Sarah', 'role' => 'treasurer'], $this->oh)->assertStatus(201);
        $this->assertSame('invited', $r->json('data.status'));
        $this->assertSame('+256772600123', $r->json('data.phone'));
        $invite = DB::table('invites')->where('company_id', $this->owner['company_id'])->where('phone_e164', '+256772600123')->first();
        $this->assertSame('viewer', $invite->role, 'they join the account as a viewer');

        // Accepting the team invite seats them (the link carries the token; tests read it back by resending).
        $link = $this->postJson("/api/v1/team/invites/{$invite->id}/resend", [], $this->oh)->assertOk()->json('data.link');
        $sarah = $this->accept(basename((string) $link));
        $seat = BudgetProgramMember::withoutGlobalScopes()->where('budget_program_id', $program)->where('user_id', $sarah['user_id'])->first();
        $this->assertNotNull($seat);
        $this->assertSame('active', $seat->status);

        $pledge = (int) $this->pledge($program, $this->oh)->json('data.id');
        $this->postJson("/api/v1/contribution-records/{$pledge}/payments", ['amount' => 2000], $sarah['h'])->assertStatus(201);

        // Someone already on the team, by phone: seated straight away, no invite.
        $this->postJson("/api/v1/budget-programs/{$this->program()}/members", ['phone' => '0772600123', 'role' => 'member'], $this->oh)->assertStatus(201)->assertJsonPath('data.status', 'active');
    }

    // ── Sync ─────────────────────────────────────────────────────────

    private function push(array $who, array $ops): array
    {
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $who['h'])->assertOk();

        return $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => $ops]]], $who['h'] + ['X-Device-Id' => $device])
            ->assertOk()->json('data.results.0');
    }

    private function op(string $table, array $data, ?string $uuid = null): array
    {
        return ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid ?? (string) Str::uuid(), 'action' => 'insert',
            'client_updated_at' => SyncSequence::nowMs(), 'version' => 0, 'data' => $data];
    }

    public function test_synced_ops_follow_the_same_committee_rules(): void
    {
        $program = $this->program();
        $programUuid = (string) DB::table('budget_programs')->where('id', $program)->value('uuid');
        $treasurer = $this->teammate('0772500021');
        $member = $this->teammate('0772500022');
        $this->seat($program, $treasurer['user_id'], 'treasurer');
        $this->seat($program, $member['user_id'], 'member');

        $pledge = (string) Str::uuid();
        $r = $this->push($member, [$this->op('contribution_records', ['name' => 'Offline pledge', 'program_uuid' => $programUuid, 'amount' => '30000'], $pledge)]);
        $this->assertSame('applied', $r['status'], json_encode($r));

        $r = $this->push($member, [$this->op('contribution_payments', ['contribution_uuid' => $pledge, 'amount' => '1000'])]);
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('record_payments', $r['ops'][0]['permission']);
        $r = $this->push($member, [$this->op('contribution_records', ['name' => 'Old app pledge', 'program_uuid' => $programUuid, 'amount' => '30000', 'paid_amount' => '30000'])]);
        $this->assertSame('record_payments', $r['ops'][0]['permission'] ?? null, json_encode($r));

        $r = $this->push($treasurer, [$this->op('contribution_payments', ['contribution_uuid' => $pledge, 'amount' => '1000'])]);
        $this->assertSame('applied', $r['status'], json_encode($r));

        // The committee itself: only the chair (or full access) changes it from a phone.
        $r = $this->push($member, [$this->op('budget_program_members', ['program_uuid' => $programUuid, 'user_id' => $member['user_id'], 'role' => 'chair'])]);
        $this->assertSame('manage_committee', $r['ops'][0]['permission'] ?? null, json_encode($r));
        $newcomer = $this->teammate('0772500023');
        $seat = (string) Str::uuid();
        $r = $this->push(['h' => $this->oh], [$this->op('budget_program_members', ['program_uuid' => $programUuid, 'user_id' => $newcomer['user_id'], 'role' => 'secretary'], $seat)]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $r = $this->push(['h' => $this->oh], [$this->op('budget_program_members', ['program_uuid' => $programUuid, 'user_id' => $this->registerTenant()['user_id'], 'role' => 'member'])]);
        $this->assertSame('rejected', $r['status'], 'a stranger cannot be seated from a phone either');

        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $this->oh)->assertOk();
        $rows = $this->getJson('/api/v1/sync/pull?table=budget_program_members&since_seq=0', $this->oh + ['X-Device-Id' => $device])->assertOk()->json('data.rows');
        $this->assertContains($seat, array_column((array) $rows, 'uuid'));
        $this->assertSame($programUuid, collect($rows)->firstWhere('uuid', $seat)['program_uuid']);
    }
}

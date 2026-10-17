<?php

namespace Tests\Feature\Api;

use App\Models\BudgetProgram;
use App\Services\Budget\CommitteeService;
use App\Services\Team\Permissions;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Whoever creates an event chairs it (CommitteeService::seatCreator): on the API, from a phone's sync and on the
 * web portal. A phone that then pushes its own committee row for the same person replaces that seat instead of
 * having its batch refused.
 */
class EventCreatorChairTest extends ApiTestCase
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

    private function seats(int $program): array
    {
        return DB::table('budget_program_members')->where('budget_program_id', $program)->where('is_deleted', 0)->pluck('role', 'user_id')->map(fn ($r) => (string) $r)->all();
    }

    private function push(array $ops): array
    {
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $this->oh)->assertOk();

        return $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => $ops]]], $this->oh + ['X-Device-Id' => $device])
            ->assertOk()->json('data.results.0');
    }

    private function op(string $table, array $data, ?string $uuid = null): array
    {
        return ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid ?? (string) Str::uuid(), 'action' => 'insert',
            'client_updated_at' => SyncSequence::nowMs(), 'version' => 0, 'data' => $data];
    }

    public function test_an_event_made_on_the_api_is_chaired_by_its_creator_once(): void
    {
        $id = (int) $this->postJson('/api/v1/budget-programs', ['name' => 'Kwanjula '.uniqid()], $this->oh)->assertStatus(201)->json('data.id');
        $this->assertSame([$this->owner['user_id'] => 'chair'], $this->seats($id));

        // An edit seats nobody again, and the committee answer lists the chair.
        $this->putJson("/api/v1/budget-programs/{$id}", ['name' => 'Renamed '.uniqid()], $this->oh)->assertOk();
        $this->assertCount(1, $this->seats($id));
        $this->getJson("/api/v1/budget-programs/{$id}/members", $this->oh)->assertOk()->assertJsonPath('data.members.0.role', 'chair')
            ->assertJsonPath('data.members.0.user_id', $this->owner['user_id']);

        // Calling it again (or for someone outside the account) does nothing.
        $program = BudgetProgram::withoutGlobalScopes()->findOrFail($id);
        $this->assertNull(CommitteeService::seatCreator($program, $this->owner['user_id']));
        $this->assertNull(CommitteeService::seatCreator($program, $this->registerTenant()['user_id']));
        $this->assertCount(1, $this->seats($id));
    }

    public function test_an_event_synced_from_a_phone_is_chaired_and_its_own_seat_push_replaces_the_creator_seat(): void
    {
        $programUuid = (string) Str::uuid();
        $r = $this->push([$this->op('budget_programs', ['name' => 'Offline wedding '.uniqid(), 'status' => 'Active'], $programUuid)]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $program = (int) DB::table('budget_programs')->where('uuid', $programUuid)->value('id');
        $this->assertSame([$this->owner['user_id'] => 'chair'], $this->seats($program));
        $creatorSeat = (int) DB::table('budget_program_members')->where('budget_program_id', $program)->value('id');

        // The phone also pushes the creator's own row (here as treasurer): applied, and it is now the one seat.
        $seat = (string) Str::uuid();
        $r = $this->push([$this->op('budget_program_members', ['program_uuid' => $programUuid, 'user_id' => $this->owner['user_id'], 'role' => 'treasurer'], $seat)]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame([$this->owner['user_id'] => 'treasurer'], $this->seats($program));
        $this->assertSame(1, (int) DB::table('budget_program_members')->where('id', $creatorSeat)->value('is_deleted'), 'a tombstone, so phones drop it');

        // Someone seated by another person is still refused a second seat.
        $link = $this->postJson('/api/v1/team/invites', ['role' => 'viewer', 'phone' => '0772500031', 'name' => 'Helper'], $this->oh)->assertStatus(201)->json('data.link');
        $helper = (int) $this->postJson('/api/v1/invites/'.basename((string) $link).'/accept', ['first_name' => 'Helper', 'last_name' => uniqid(), 'password' => 'secret123'])->assertStatus(201)->json('data.user.id');
        Permissions::flush();
        $this->postJson("/api/v1/budget-programs/{$program}/members", ['user_id' => $helper, 'role' => 'secretary'], $this->oh)->assertStatus(201);
        $r = $this->push([$this->op('budget_program_members', ['program_uuid' => $programUuid, 'user_id' => $helper, 'role' => 'member'])]);
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('already_on_committee', $r['ops'][0]['code'] ?? null);
    }
}

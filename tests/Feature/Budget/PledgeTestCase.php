<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetProgram;
use App\Models\ContributionRecord;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\ApiTestCase;

/** An account with an owner, teammates and events, reached through the API and sync as a phone reaches them. */
abstract class PledgeTestCase extends ApiTestCase
{
    protected array $o;

    protected array $oh;

    protected ?array $deviceHeaders = null;

    private int $nextPhone = 500100;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::flush();
        $this->o = $this->registerTenant();
        $this->oh = $this->auth($this->o['token']);
    }

    protected function ownerUser(): User
    {
        return User::withoutGlobalScopes()->findOrFail($this->o['user_id']);
    }

    protected function cid(): int
    {
        return (int) $this->o['company_id'];
    }

    /** A team member (account role viewer unless given), so only the seat counts. */
    protected function teammate(string $accountRole = 'viewer'): array
    {
        $phone = '0772'.(string) $this->nextPhone++;
        $link = $this->postJson('/api/v1/team/invites', ['role' => $accountRole, 'phone' => $phone, 'name' => 'Helper'], $this->oh)->assertStatus(201)->json('data.link');
        $r = $this->postJson('/api/v1/invites/'.basename($link).'/accept', ['first_name' => 'Helper', 'last_name' => uniqid(), 'password' => 'secret123'])->assertStatus(201);
        Permissions::flush();

        return ['user_id' => (int) $r->json('data.user.id'), 'h' => $this->auth($r->json('data.token')), 'user' => User::withoutGlobalScopes()->findOrFail((int) $r->json('data.user.id'))];
    }

    protected function seat(int $program, array $mate, string $role): void
    {
        $this->postJson("/api/v1/budget-programs/{$program}/members", ['user_id' => $mate['user_id'], 'role' => $role], $this->oh)->assertStatus(201);
    }

    protected function program(string $name = 'Wedding'): int
    {
        return (int) $this->postJson('/api/v1/budget-programs', ['name' => $name.' '.uniqid()], $this->oh)->assertStatus(201)->json('data.id');
    }

    protected function programModel(int $id): BudgetProgram
    {
        return BudgetProgram::withoutGlobalScopes()->findOrFail($id);
    }

    protected function pledge(int $program, array $extra = [], ?array $h = null): array
    {
        return $this->postJson('/api/v1/contribution-records', $extra + ['budget_program_id' => $program, 'name' => 'Pledger '.uniqid(), 'amount' => 500000], $h ?? $this->oh)
            ->assertStatus(201)->json('data');
    }

    protected function pledgeModel(int $id): ContributionRecord
    {
        return ContributionRecord::withoutGlobalScopes()->without(['budgetProgram', 'treasurer', 'changedBy'])->findOrFail($id);
    }

    protected function pay(int $pledgeId, array $body, ?array $h = null)
    {
        return $this->postJson("/api/v1/contribution-records/{$pledgeId}/payments", $body, $h ?? $this->oh);
    }

    protected function close(int $program): void
    {
        $this->putJson("/api/v1/budget-programs/{$program}", ['status' => 'Inactive'], $this->oh)->assertOk();
    }

    protected function reopen(int $program): void
    {
        $this->putJson("/api/v1/budget-programs/{$program}", ['status' => 'Active'], $this->oh)->assertOk();
    }

    protected function programUuid(int $id): string
    {
        return (string) DB::table('budget_programs')->where('id', $id)->value('uuid');
    }

    /** The batch's result from a phone of the given person (the owner by default). */
    protected function push(array $ops, ?array $h = null): array
    {
        if ($h === null) {
            if ($this->deviceHeaders === null) {
                $device = (string) Str::uuid();
                $this->postJson('/api/v1/devices/register', ['device_id' => $device], $this->oh)->assertOk();
                $this->deviceHeaders = $this->oh + ['X-Device-Id' => $device];
            }
            $h = $this->deviceHeaders;
        }

        return $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => $ops]]], $h)->assertOk()->json('data.results.0');
    }

    protected function op(string $table, array $data, ?string $uuid = null, string $action = 'insert', ?int $madeAt = null): array
    {
        $at = $madeAt ?? SyncSequence::nowMs();

        return ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid ?? (string) Str::uuid(), 'action' => $action,
            'client_updated_at' => $at, 'client_created_at' => $at, 'version' => 0, 'data' => $data];
    }

    /** The money identity of an event, from EventBook, on every figure. */
    protected function assertIdentity(array $event): void
    {
        $left = bcadd(bcsub($event['pledged'], $event['collected'], 2), $event['extra'], 2);
        $this->assertSame($event['still_owed'], $left, 'Pledged - Collected + Extra = Still owed');
    }
}

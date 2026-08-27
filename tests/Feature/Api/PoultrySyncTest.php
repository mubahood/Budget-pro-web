<?php

namespace Tests\Feature\Api;

class PoultrySyncTest extends ApiTestCase
{
    private function pushBatch(string $token, string $uuid, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/poultry/sync/push', [
            'table' => 'batches',
            'uuid' => $uuid,
            'json' => array_merge([
                'uuid' => $uuid,
                'name' => 'Batch A',
                'type' => 'layer',
                'source' => 'Hatchery',
                'acquired_date' => '2026-08-01',
                'start_count' => 100,
                'cost_per_chick' => 3500,
                'status' => 'active',
                'notes' => '',
                'is_main_farm' => 1,
                'created_at' => 1000,
                'updated_at' => 1000,
                'version' => 1,
                'entered_by' => 'Owner',
            ], $overrides),
        ], $this->auth($token));
    }

    public function test_push_creates_a_new_batch(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $res = $this->pushBatch($t['token'], $uuid);

        $res->assertOk()->assertJsonPath('code', 1)
            ->assertJsonPath('data.conflict', false)
            ->assertJsonPath('data.server_data.uuid', $uuid)
            ->assertJsonPath('data.server_data.name', 'Batch A');

        $this->assertDatabaseHas('poultry_batches', [
            'uuid' => $uuid, 'company_id' => $t['company_id'], 'name' => 'Batch A', 'start_count' => 100,
        ]);
    }

    public function test_push_update_with_newer_timestamp_wins(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->pushBatch($t['token'], $uuid, ['updated_at' => 1000, 'name' => 'Original'])->assertOk();
        $res = $this->pushBatch($t['token'], $uuid, ['updated_at' => 2000, 'name' => 'Updated']);

        $res->assertOk()->assertJsonPath('data.conflict', false)
            ->assertJsonPath('data.server_data.name', 'Updated');
        $this->assertDatabaseHas('poultry_batches', ['uuid' => $uuid, 'name' => 'Updated']);
        $this->assertDatabaseMissing('poultry_batches', ['uuid' => $uuid, 'name' => 'Original']);
    }

    public function test_push_with_older_timestamp_is_rejected_as_conflict_and_db_unchanged(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->pushBatch($t['token'], $uuid, ['updated_at' => 5000, 'name' => 'ServerWins'])->assertOk();
        $res = $this->pushBatch($t['token'], $uuid, ['updated_at' => 1000, 'name' => 'StaleClient']);

        $res->assertOk()
            ->assertJsonPath('data.conflict', true)
            ->assertJsonPath('data.server_data.name', 'ServerWins');

        // The DB row must be completely unchanged by the losing push.
        $this->assertDatabaseHas('poultry_batches', ['uuid' => $uuid, 'name' => 'ServerWins']);
        $this->assertDatabaseMissing('poultry_batches', ['uuid' => $uuid, 'name' => 'StaleClient']);
    }

    public function test_push_delete_tombstones_the_row(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->pushBatch($t['token'], $uuid, ['updated_at' => 1000])->assertOk();

        $res = $this->postJson('/api/v1/poultry/sync/push', [
            'table' => 'batches',
            'uuid' => $uuid,
            'is_delete' => true,
            'json' => ['uuid' => $uuid, 'updated_at' => 2000, 'created_at' => 1000],
        ], $this->auth($t['token']));

        $res->assertOk()->assertJsonPath('data.server_data.is_deleted', 1);
        $this->assertDatabaseHas('poultry_batches', ['uuid' => $uuid, 'is_deleted' => 1]);
    }

    public function test_pull_respects_since_cursor_and_is_empty_once_caught_up(): void
    {
        $t = $this->registerTenant();
        $uuidA = (string) \Illuminate\Support\Str::uuid();
        $uuidB = (string) \Illuminate\Support\Str::uuid();

        $this->pushBatch($t['token'], $uuidA, ['updated_at' => 1000])->assertOk();
        $this->pushBatch($t['token'], $uuidB, ['updated_at' => 2000])->assertOk();

        $full = $this->getJson('/api/v1/poultry/sync/pull?table=batches&since=0', $this->auth($t['token']));
        $full->assertOk();
        $this->assertCount(2, $full->json('data.rows'));
        $cursor = $full->json('data.new_cursor');
        $this->assertGreaterThanOrEqual(2000, $cursor);

        $partial = $this->getJson('/api/v1/poultry/sync/pull?table=batches&since=1000', $this->auth($t['token']));
        $partial->assertOk();
        $this->assertCount(1, $partial->json('data.rows'));
        $this->assertSame($uuidB, $partial->json('data.rows.0.uuid'));

        $caughtUp = $this->getJson('/api/v1/poultry/sync/pull?table=batches&since='.$cursor, $this->auth($t['token']));
        $caughtUp->assertOk();
        $this->assertCount(0, $caughtUp->json('data.rows'));
    }

    public function test_cross_tenant_push_and_pull_isolation(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->pushBatch($a['token'], $uuid, ['updated_at' => 1000, 'name' => 'CompanyA Batch'])->assertOk();

        // Company B pushing the SAME uuid must not touch company A's row —
        // it creates its own row scoped to company B instead.
        $this->pushBatch($b['token'], $uuid, ['updated_at' => 9999, 'name' => 'CompanyB Batch'])->assertOk();

        $this->assertDatabaseHas('poultry_batches', [
            'uuid' => $uuid, 'company_id' => $a['company_id'], 'name' => 'CompanyA Batch',
        ]);
        $this->assertDatabaseHas('poultry_batches', [
            'uuid' => $uuid, 'company_id' => $b['company_id'], 'name' => 'CompanyB Batch',
        ]);

        // Company B's pull must never see company A's rows.
        $pull = $this->getJson('/api/v1/poultry/sync/pull?table=batches&since=0', $this->auth($b['token']));
        $names = collect($pull->json('data.rows'))->pluck('name')->all();
        $this->assertContains('CompanyB Batch', $names);
        $this->assertNotContains('CompanyA Batch', $names);
    }

    public function test_daily_record_push_resolves_batch_uuid_to_local_batch_and_round_trips(): void
    {
        $t = $this->registerTenant();
        $batchUuid = (string) \Illuminate\Support\Str::uuid();
        $recordUuid = (string) \Illuminate\Support\Str::uuid();

        $this->pushBatch($t['token'], $batchUuid, ['updated_at' => 1000])->assertOk();

        $res = $this->postJson('/api/v1/poultry/sync/push', [
            'table' => 'daily_records',
            'uuid' => $recordUuid,
            'json' => [
                'uuid' => $recordUuid,
                'batch_uuid' => $batchUuid,
                'date' => '2026-08-27',
                'eggs_trays' => 3,
                'eggs_loose' => 5,
                'mortality' => 1,
                'feed_kg' => 25,
                'water_l' => 40,
                'egg_unit_price' => 500,
                'feed_price_per_kg' => 2000,
                'created_at' => 1000,
                'updated_at' => 1000,
            ],
        ], $this->auth($t['token']));

        $res->assertOk()->assertJsonPath('data.conflict', false);

        $batchId = \App\Models\PoultryBatch::withoutGlobalScopes()->where('uuid', $batchUuid)->value('id');
        $this->assertDatabaseHas('poultry_daily_records', ['uuid' => $recordUuid, 'batch_id' => $batchId, 'eggs_trays' => 3]);

        // Pulling it back must return batch_uuid (not the internal id) —
        // exactly what the mobile client's fromMap() expects.
        $pull = $this->getJson('/api/v1/poultry/sync/pull?table=daily_records&since=0', $this->auth($t['token']));
        $pull->assertOk()->assertJsonPath('data.rows.0.batch_uuid', $batchUuid);
    }

    public function test_push_with_unsynced_parent_uuid_does_not_hard_fail(): void
    {
        $t = $this->registerTenant();
        $recordUuid = (string) \Illuminate\Support\Str::uuid();
        $neverSyncedBatchUuid = (string) \Illuminate\Support\Str::uuid();

        $res = $this->postJson('/api/v1/poultry/sync/push', [
            'table' => 'daily_records',
            'uuid' => $recordUuid,
            'json' => [
                'uuid' => $recordUuid,
                'batch_uuid' => $neverSyncedBatchUuid,
                'date' => '2026-08-27',
                'eggs_trays' => 1,
                'created_at' => 1000,
                'updated_at' => 1000,
            ],
        ], $this->auth($t['token']));

        // Must succeed (not 500) with a null batch_id, per §8.2's explicit
        // "never hard-fail a push solely because the referenced parent uuid
        // isn't present yet" rule.
        $res->assertOk()->assertJsonPath('data.conflict', false);
        $this->assertDatabaseHas('poultry_daily_records', ['uuid' => $recordUuid, 'batch_id' => null]);
    }

    public function test_push_rejected_for_reference_tables(): void
    {
        $t = $this->registerTenant();

        $this->postJson('/api/v1/poultry/sync/push', [
            'table' => 'farm_types',
            'uuid' => 'layer',
            'json' => ['name' => 'Hacked'],
        ], $this->auth($t['token']))->assertStatus(422)->assertJsonPath('code', 0);
    }

    public function test_push_rejected_for_unknown_table(): void
    {
        $t = $this->registerTenant();

        $this->postJson('/api/v1/poultry/sync/push', [
            'table' => 'not_a_real_table',
            'uuid' => 'x',
            'json' => [],
        ], $this->auth($t['token']))->assertStatus(422);
    }

    public function test_pull_farm_types_reference_data(): void
    {
        $t = $this->registerTenant();

        $res = $this->getJson('/api/v1/poultry/sync/pull?table=farm_types&since=0', $this->auth($t['token']));

        $res->assertOk();
        $slugs = collect($res->json('data.rows'))->pluck('slug')->all();
        $this->assertContains('layer', $slugs);
        $this->assertContains('broiler', $slugs);
        $this->assertContains('kienyeji', $slugs);
    }

    public function test_sync_requires_authentication(): void
    {
        $this->getJson('/api/v1/poultry/sync/pull?table=batches&since=0')->assertStatus(401);
        $this->postJson('/api/v1/poultry/sync/push', ['table' => 'batches', 'uuid' => 'x', 'json' => []])->assertStatus(401);
    }
}

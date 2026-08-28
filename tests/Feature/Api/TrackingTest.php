<?php

namespace Tests\Feature\Api;

class TrackingTest extends ApiTestCase
{
    public function test_register_creates_a_device_with_default_config(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $res = $this->postJson('/api/v1/tracking/devices/register', [
            'uuid' => $uuid,
            'name' => 'Test Phone',
            'platform' => 'android',
            'model' => 'Pixel 8',
        ], $this->auth($t['token']));

        $res->assertOk()->assertJsonPath('code', 1)
            ->assertJsonPath('data.device_id', $uuid)
            ->assertJsonPath('data.tracking_enabled', true)
            ->assertJsonPath('data.config.tracking_interval_seconds', 60);

        $this->assertDatabaseHas('tracked_devices', [
            'uuid' => $uuid, 'company_id' => $t['company_id'], 'name' => 'Test Phone',
        ]);
    }

    public function test_register_is_idempotent_for_the_same_uuid(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'First Name'], $this->auth($t['token']))->assertOk();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Renamed'], $this->auth($t['token']))->assertOk();

        $this->assertDatabaseCount('tracked_devices', 1);
        $this->assertDatabaseHas('tracked_devices', ['uuid' => $uuid, 'name' => 'Renamed']);
    }

    public function test_push_locations_batch_updates_last_known_snapshot_from_the_newest_point(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($t['token']))->assertOk();

        $base = 1787900000000;
        $res = $this->postJson("/api/v1/tracking/devices/$uuid/locations/batch", [
            'points' => [
                ['recorded_at' => $base + 1000, 'lat' => 1.0, 'lng' => 32.0, 'battery_pct' => 90],
                // Out of order on purpose — the LATER point has a SMALLER array index.
                ['recorded_at' => $base + 3000, 'lat' => 1.5, 'lng' => 32.5, 'battery_pct' => 88],
                ['recorded_at' => $base + 2000, 'lat' => 1.2, 'lng' => 32.2, 'battery_pct' => 89],
            ],
        ], $this->auth($t['token']));

        $res->assertOk()->assertJsonPath('data.saved', 3);
        $this->assertDatabaseCount('device_locations', 3);

        // "Last known" must reflect recorded_at=3000, not insertion order.
        $this->assertDatabaseHas('tracked_devices', [
            'uuid' => $uuid, 'last_lat' => 1.5, 'last_lng' => 32.5, 'last_battery_pct' => 88,
        ]);
    }

    public function test_push_locations_rejects_unregistered_device(): void
    {
        $t = $this->registerTenant();

        $this->postJson('/api/v1/tracking/devices/not-a-real-uuid/locations/batch', [
            'points' => [['recorded_at' => 1787900001000, 'lat' => 1.0, 'lng' => 32.0]],
        ], $this->auth($t['token']))->assertStatus(404);
    }

    public function test_get_config_returns_pending_commands_and_updates_last_seen(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($t['token']))->assertOk();

        $deviceId = \App\Models\TrackedDevice::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
        \App\Models\DeviceCommand::create(['device_id' => $deviceId, 'command' => 'locate_now', 'status' => 'pending']);

        $res = $this->getJson("/api/v1/tracking/devices/$uuid/config", $this->auth($t['token']));

        $res->assertOk()
            ->assertJsonPath('data.tracking_interval_seconds', 60)
            ->assertJsonCount(1, 'data.pending_commands')
            ->assertJsonPath('data.pending_commands.0.command', 'locate_now');

        $this->assertDatabaseHas('tracked_devices', ['uuid' => $uuid]);
        $lastSeen = \App\Models\TrackedDevice::withoutGlobalScopes()->where('uuid', $uuid)->value('last_seen_at');
        $this->assertNotNull($lastSeen);
    }

    public function test_ack_command_marks_it_executed(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($t['token']))->assertOk();

        $deviceId = \App\Models\TrackedDevice::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
        $command = \App\Models\DeviceCommand::create(['device_id' => $deviceId, 'command' => 'locate_now', 'status' => 'pending']);

        $this->postJson("/api/v1/tracking/devices/$uuid/commands/{$command->id}/ack", [], $this->auth($t['token']))
            ->assertOk();

        $this->assertDatabaseHas('device_commands', ['id' => $command->id, 'status' => 'executed']);

        // Acked commands must disappear from the next config pull.
        $res = $this->getJson("/api/v1/tracking/devices/$uuid/config", $this->auth($t['token']));
        $res->assertJsonCount(0, 'data.pending_commands');
    }

    public function test_cross_tenant_isolation(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Company A Phone'], $this->auth($a['token']))->assertOk();

        // Company B pushing to the SAME uuid must not see/touch company A's device.
        $this->postJson("/api/v1/tracking/devices/$uuid/locations/batch", [
            'points' => [['recorded_at' => 1787900001000, 'lat' => 1.0, 'lng' => 32.0]],
        ], $this->auth($b['token']))->assertStatus(404);

        $this->assertDatabaseHas('tracked_devices', ['uuid' => $uuid, 'company_id' => $a['company_id']]);
        $this->assertDatabaseMissing('tracked_devices', ['uuid' => $uuid, 'company_id' => $b['company_id']]);
    }

    public function test_tracking_requires_authentication(): void
    {
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => 'x', 'name' => 'Phone'])->assertStatus(401);
        $this->getJson('/api/v1/tracking/devices/x/config')->assertStatus(401);
    }
}

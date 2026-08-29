<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Http;

class TrackingTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every test in this file goes through pushLocations() at least
        // indirectly, and that path calls out to Nominatim whenever
        // geocoding is enabled (the default) — faking it keeps the whole
        // suite fast, deterministic, and off the real rate-limited API.
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'display_name' => 'Test Road, Test Town, Test Country',
                'address' => ['road' => 'Test Road', 'city' => 'Test Town', 'country' => 'Test Country'],
            ], 200),
        ]);
    }

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

    public function test_push_locations_resolves_and_stores_place_names(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($t['token']))->assertOk();

        $this->postJson("/api/v1/tracking/devices/$uuid/locations/batch", [
            'points' => [['recorded_at' => 1787900001000, 'lat' => 1.111111, 'lng' => 32.222222]],
        ], $this->auth($t['token']))->assertOk();

        $this->assertDatabaseHas('device_locations', [
            'lat' => 1.111111, 'lng' => 32.222222, 'place_name' => 'Test Road, Test Town, Test Country',
        ]);
        $this->assertDatabaseHas('tracked_devices', [
            'uuid' => $uuid, 'last_location_name' => 'Test Road, Test Town, Test Country',
        ]);
        $this->assertDatabaseHas('geocode_cache', ['lat_rounded' => 1.1111, 'lng_rounded' => 32.2222]);
    }

    public function test_push_locations_reuses_geocode_cache_for_repeated_coordinates(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($t['token']))->assertOk();

        $point = ['lat' => 2.5, 'lng' => 33.5];
        $this->postJson("/api/v1/tracking/devices/$uuid/locations/batch", [
            'points' => [[...$point, 'recorded_at' => 1787900001000]],
        ], $this->auth($t['token']))->assertOk();
        $this->postJson("/api/v1/tracking/devices/$uuid/locations/batch", [
            'points' => [[...$point, 'recorded_at' => 1787900002000]],
        ], $this->auth($t['token']))->assertOk();

        // Second push hits the geocode_cache row the first push created —
        // Nominatim itself should only ever have been called once.
        Http::assertSentCount(1);
        $this->assertDatabaseCount('device_locations', 2);
    }

    public function test_geocoding_disabled_skips_place_name_resolution(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($t['token']))->assertOk();
        $this->postJson("/api/v1/tracking/devices/$uuid/config", ['geocoding_enabled' => false], $this->auth($t['token']))->assertOk();

        $this->postJson("/api/v1/tracking/devices/$uuid/locations/batch", [
            'points' => [['recorded_at' => 1787900001000, 'lat' => 4.0, 'lng' => 34.0]],
        ], $this->auth($t['token']))->assertOk();

        Http::assertNothingSent();
        $this->assertDatabaseHas('device_locations', ['lat' => 4.0, 'lng' => 34.0, 'place_name' => null]);
    }

    public function test_update_config_persists_advanced_fields_and_round_trips_via_get_config(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($t['token']))->assertOk();

        $res = $this->postJson("/api/v1/tracking/devices/$uuid/config", [
            'tracking_enabled' => false,
            'tracking_interval_seconds' => 120,
            'min_distance_meters' => 50,
            'stationary_interval_seconds' => 300,
            'low_battery_threshold_pct' => 10,
        ], $this->auth($t['token']));

        $res->assertOk()
            ->assertJsonPath('data.tracking_enabled', false)
            ->assertJsonPath('data.tracking_interval_seconds', 120)
            ->assertJsonPath('data.min_distance_meters', 50)
            ->assertJsonPath('data.stationary_interval_seconds', 300)
            ->assertJsonPath('data.low_battery_threshold_pct', 10);

        // The admin panel (and any other reader) must see the exact same
        // values the device just pushed — server stays the single source
        // of truth for both sides.
        $get = $this->getJson("/api/v1/tracking/devices/$uuid/config", $this->auth($t['token']));
        $get->assertJsonPath('data.tracking_enabled', false)
            ->assertJsonPath('data.tracking_interval_seconds', 120)
            ->assertJsonPath('data.min_distance_meters', 50);
    }

    public function test_update_config_rejects_cross_tenant_device(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($a['token']))->assertOk();

        $this->postJson("/api/v1/tracking/devices/$uuid/config", ['tracking_interval_seconds' => 999], $this->auth($b['token']))
            ->assertStatus(404);
    }

    public function test_register_records_consent_for_the_device(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($t['token']))->assertOk();

        $deviceId = \App\Models\TrackedDevice::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
        $this->assertTrue(\App\Models\PingPinDeviceConsent::isActiveFor($deviceId));
        $this->assertDatabaseHas('pingpin_device_consents', [
            'device_id' => $deviceId, 'consented_by_user_id' => $t['user_id'], 'consent_text_version' => 'implicit-v0-self-registration',
        ]);
    }

    public function test_register_does_not_duplicate_consent_on_idempotent_re_register(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($t['token']))->assertOk();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Renamed'], $this->auth($t['token']))->assertOk();

        $deviceId = \App\Models\TrackedDevice::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
        $this->assertDatabaseCount('pingpin_device_consents', 1);
        $this->assertEquals(1, \App\Models\PingPinDeviceConsent::where('device_id', $deviceId)->count());
    }

    public function test_push_locations_rejected_once_consent_is_revoked(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Phone'], $this->auth($t['token']))->assertOk();

        $deviceId = \App\Models\TrackedDevice::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
        \App\Models\PingPinDeviceConsent::where('device_id', $deviceId)->update(['revoked_at' => now()]);

        $res = $this->postJson("/api/v1/tracking/devices/$uuid/locations/batch", [
            'points' => [['recorded_at' => 1787900001000, 'lat' => 1.0, 'lng' => 32.0]],
        ], $this->auth($t['token']));

        $res->assertStatus(403);
        $this->assertDatabaseCount('device_locations', 0);
    }

    public function test_register_stores_declared_capabilities(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->postJson('/api/v1/tracking/devices/register', [
            'uuid' => $uuid, 'name' => 'Phone',
            'capabilities' => [
                \App\Models\PingPinDeviceCapability::BACKGROUND_LOCATION => true,
                \App\Models\PingPinDeviceCapability::REMOTE_WIPE => false,
                'not_a_real_capability' => true,
            ],
        ], $this->auth($t['token']))->assertOk();

        $deviceId = \App\Models\TrackedDevice::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
        $this->assertTrue(\App\Models\PingPinDeviceCapability::supports($deviceId, \App\Models\PingPinDeviceCapability::BACKGROUND_LOCATION));
        $this->assertFalse(\App\Models\PingPinDeviceCapability::supports($deviceId, \App\Models\PingPinDeviceCapability::REMOTE_WIPE));
        // A capability never declared at all defaults to allowed (see PingPinDeviceCapability::supports doc).
        $this->assertTrue(\App\Models\PingPinDeviceCapability::supports($deviceId, \App\Models\PingPinDeviceCapability::SIM_WATCH));
        // Unknown keys are silently dropped, never stored under any name.
        $this->assertDatabaseMissing('pingpin_device_capabilities', ['device_id' => $deviceId, 'capability' => 'not_a_real_capability']);
        $this->assertDatabaseCount('pingpin_device_capabilities', 2);
    }

    public function test_re_registering_updates_a_previously_declared_capability(): void
    {
        $t = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->postJson('/api/v1/tracking/devices/register', [
            'uuid' => $uuid, 'name' => 'Phone',
            'capabilities' => [\App\Models\PingPinDeviceCapability::REMOTE_RING => false],
        ], $this->auth($t['token']))->assertOk();

        $this->postJson('/api/v1/tracking/devices/register', [
            'uuid' => $uuid, 'name' => 'Phone',
            'capabilities' => [\App\Models\PingPinDeviceCapability::REMOTE_RING => true],
        ], $this->auth($t['token']))->assertOk();

        $deviceId = \App\Models\TrackedDevice::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
        $this->assertTrue(\App\Models\PingPinDeviceCapability::supports($deviceId, \App\Models\PingPinDeviceCapability::REMOTE_RING));
        $this->assertDatabaseCount('pingpin_device_capabilities', 1);
    }
}

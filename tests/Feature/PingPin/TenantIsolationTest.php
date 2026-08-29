<?php

namespace Tests\Feature\PingPin;

use App\Models\CompanyMember;
use App\Models\TrackedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\ApiTestCase;

/**
 * The mandatory cross-tenant isolation test (brief §2): "tenant A cannot
 * read, write, or enumerate tenant B's data." Deliberately its own file,
 * separate from the more granular per-feature tests in OrganisationServiceTest
 * / EnsurePingPinMembershipTest / OrganisationControllerTest / TrackingTest —
 * this is the canonical, always-run-in-CI proof, organized explicitly around
 * the three properties the brief names, across every Ping Pin endpoint that
 * exists as of Task 1.7 (organisation membership + device tracking).
 */
class TenantIsolationTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'x'], 200)]);
    }

    public function test_enumerate_organisation_membership_never_leaks_across_tenants(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();

        $res = $this->getJson('/api/v1/pingpin/organisations', $this->auth($a['token']));
        $res->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.company_id', $a['company_id']);

        $companyIds = collect($res->json('data'))->pluck('company_id');
        $this->assertFalse($companyIds->contains($b['company_id']), "A's organisation list must never contain B's company id");
    }

    public function test_write_organisation_membership_across_tenants_is_rejected(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $bMember = User::create(['username' => 'bm_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'BMember']);
        CompanyMember::create(['company_id' => $b['company_id'], 'user_id' => $bMember->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);

        $this->postJson("/api/v1/pingpin/organisations/{$b['company_id']}/members/invite", ['identifier' => 'x@example.com', 'role' => 'member'], $this->auth($a['token']))->assertStatus(403);
        $this->postJson("/api/v1/pingpin/organisations/{$b['company_id']}/members/{$bMember->id}/revoke", [], $this->auth($a['token']))->assertStatus(403);
        $this->postJson("/api/v1/pingpin/organisations/{$b['company_id']}/members/{$bMember->id}/role", ['role' => 'admin'], $this->auth($a['token']))->assertStatus(403);
        $this->postJson("/api/v1/pingpin/organisations/{$b['company_id']}/transfer-ownership", ['to_user_id' => $bMember->id], $this->auth($a['token']))->assertStatus(403);

        // Nothing in B's organisation moved as a result of any of the above.
        $this->assertDatabaseHas('company_members', ['company_id' => $b['company_id'], 'user_id' => $bMember->id, 'role' => 'member', 'status' => 'active']);
        $this->assertDatabaseMissing('company_members', ['company_id' => $b['company_id'], 'invited_email' => 'x@example.com']);
    }

    public function test_read_a_cross_tenant_device_is_rejected(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'B Phone'], $this->auth($b['token']))->assertOk();

        // A's token, B's device uuid — every read-shaped endpoint must 404,
        // not silently return B's config as if it were empty/default.
        $this->getJson("/api/v1/tracking/devices/$uuid/config", $this->auth($a['token']))->assertStatus(404);
    }

    public function test_write_a_cross_tenant_device_is_rejected(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'B Phone'], $this->auth($b['token']))->assertOk();

        $this->postJson("/api/v1/tracking/devices/$uuid/locations/batch", [
            'points' => [['recorded_at' => 1787900001000, 'lat' => 1.0, 'lng' => 32.0]],
        ], $this->auth($a['token']))->assertStatus(404);

        $this->postJson("/api/v1/tracking/devices/$uuid/config", ['tracking_interval_seconds' => 999], $this->auth($a['token']))->assertStatus(404);

        $deviceId = TrackedDevice::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
        $this->assertDatabaseCount('device_locations', 0);
        $this->assertDatabaseMissing('device_configs', ['device_id' => $deviceId, 'tracking_interval_seconds' => 999]);
    }

    public function test_registering_with_another_tenants_uuid_is_a_clean_rejection_not_a_takeover(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $bUuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $bUuid, 'name' => 'B Phone'], $this->auth($b['token']))->assertOk();

        // A presenting B's exact uuid must be a clean, explicit rejection —
        // never a silent takeover of B's device, and never an unhandled SQL
        // error from the uuid column's global unique constraint (a real bug
        // this test caught: it used to be a raw 500).
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $bUuid, 'name' => 'A Phone (same uuid)'], $this->auth($a['token']))
            ->assertStatus(409);

        $this->assertDatabaseHas('tracked_devices', ['uuid' => $bUuid, 'company_id' => $b['company_id'], 'name' => 'B Phone']);
        $this->assertDatabaseCount('tracked_devices', 1);
    }
}

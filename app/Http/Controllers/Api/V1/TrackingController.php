<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceConfig;
use App\Models\PingPinDeviceCapability;
use App\Models\PingPinDeviceConsent;
use App\Models\TrackedDevice;
use App\Services\GeocodingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Find My Phone — device registration, location push, config/command pull.
 * One-way (device -> server for locations, server -> device for config), so
 * unlike the poultry sync there's no conflict resolution to speak of: a
 * location point is an immutable log entry, never edited after the fact.
 */
class TrackingController extends Controller
{
    use ApiResponse;

    private const DEFAULT_CONFIG = [
        'tracking_interval_seconds' => 60,
        'high_accuracy_mode' => true,
        'min_distance_meters' => 0,
        'stationary_interval_seconds' => null,
        'geocoding_enabled' => true,
        'low_battery_threshold_pct' => 5,
    ];

    /** Live calls Nominatim allows per push request beyond the guaranteed latest-point lookup. */
    private const MAX_LIVE_GEOCODES_PER_PUSH = 3;

    /** First-run (and idempotent re-run): find-or-create by the device's own generated uuid. */
    public function register(Request $r)
    {
        $data = $r->validate([
            'uuid' => 'required|string|max:191',
            'name' => 'required|string|max:191',
            'platform' => 'sometimes|string|max:16',
            'model' => 'sometimes|nullable|string|max:191',
            'os_version' => 'sometimes|nullable|string|max:64',
            'app_version' => 'sometimes|nullable|string|max:32',
            'capabilities' => 'sometimes|array',
            'capabilities.*' => 'boolean',
        ]);

        $companyId = (int) $r->user()->company_id;

        $device = TrackedDevice::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('uuid', $data['uuid'])->first();

        if (! $device) {
            $device = new TrackedDevice();
            $device->company_id = $companyId;
            $device->user_id = $r->user()->id;
            // Set explicitly rather than relying on the DB column default —
            // the in-memory model won't reflect a DB-applied default until
            // a refresh(), and the response below reads it immediately.
            $device->tracking_enabled = true;
            $device->uuid = $data['uuid'];
        }

        $device->name = $data['name'];
        $device->platform = $data['platform'] ?? $device->platform ?? 'android';
        $device->model = $data['model'] ?? $device->model;
        $device->os_version = $data['os_version'] ?? $device->os_version;
        $device->app_version = $data['app_version'] ?? $device->app_version;
        $device->save();

        // Interim consent policy (DECISIONS.md D11): the person registering
        // a device via this endpoint IS the person physically setting up
        // tracking on it (self-registration, no invite/remote-enrolment
        // flow exists yet), so their own registration action is recorded as
        // the consent event. Recorded once per device, never re-recorded on
        // an idempotent re-register.
        if (! PingPinDeviceConsent::isActiveFor($device->id)) {
            PingPinDeviceConsent::create([
                'device_id' => $device->id,
                'consented_by_user_id' => $r->user()->id,
                'consented_at' => now(),
                'consent_text_version' => 'implicit-v0-self-registration',
            ]);
        }

        // Capability declaration (brief §6 / PLAN.md §5): only known keys are
        // recorded, so a client typo or a future/unreleased capability name
        // can never silently create an unenforceable row.
        $declaredNow = now();
        foreach ($data['capabilities'] ?? [] as $capability => $supported) {
            if (! in_array($capability, PingPinDeviceCapability::ALL, true)) {
                continue;
            }
            PingPinDeviceCapability::updateOrCreate(
                ['device_id' => $device->id, 'capability' => $capability],
                ['supported' => (bool) $supported, 'declared_at' => $declaredNow]
            );
        }

        $config = DeviceConfig::firstOrCreate(['device_id' => $device->id], self::DEFAULT_CONFIG);

        return $this->success([
            'device_id' => $device->uuid,
            'tracking_enabled' => $device->tracking_enabled,
            'config' => $this->configPayload($config),
        ], 'Device registered.');
    }

    /** Bulk push of queued location points. */
    public function pushLocations(Request $r, string $uuid)
    {
        $data = $r->validate([
            'points' => 'required|array|min:1|max:500',
            'points.*.recorded_at' => 'required|integer',
            'points.*.lat' => 'required|numeric|between:-90,90',
            'points.*.lng' => 'required|numeric|between:-180,180',
            'points.*.accuracy_m' => 'sometimes|nullable|numeric',
            'points.*.altitude_m' => 'sometimes|nullable|numeric',
            'points.*.speed_mps' => 'sometimes|nullable|numeric',
            'points.*.heading_deg' => 'sometimes|nullable|numeric',
            'points.*.activity' => 'sometimes|nullable|string|max:16',
            'points.*.battery_pct' => 'sometimes|nullable|integer|between:0,100',
            'points.*.network' => 'sometimes|nullable|string|max:16',
        ]);

        $companyId = (int) $r->user()->company_id;
        $device = TrackedDevice::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('uuid', $uuid)->first();

        if (! $device) {
            return $this->error('Device not found. Register it first.', 404);
        }

        if (! PingPinDeviceConsent::isActiveFor($device->id)) {
            return $this->error('Location sharing consent has been revoked for this device.', 403);
        }

        $config = DeviceConfig::firstOrCreate(['device_id' => $device->id], self::DEFAULT_CONFIG);

        $placeNames = $config->geocoding_enabled
            ? $this->resolvePlaceNames($data['points'], app(GeocodingService::class))
            : [];

        $now = now();
        $rows = array_map(fn ($p) => [
            'company_id' => $companyId,
            'device_id' => $device->id,
            'lat' => $p['lat'],
            'lng' => $p['lng'],
            'place_name' => $placeNames[$this->coordKey($p['lat'], $p['lng'])] ?? null,
            'accuracy_m' => $p['accuracy_m'] ?? null,
            'altitude_m' => $p['altitude_m'] ?? null,
            'speed_mps' => $p['speed_mps'] ?? null,
            'heading_deg' => $p['heading_deg'] ?? null,
            'activity' => $p['activity'] ?? null,
            'battery_pct' => $p['battery_pct'] ?? null,
            'network' => $p['network'] ?? null,
            'recorded_at' => $p['recorded_at'],
            'created_at' => $now,
        ], $data['points']);

        DB::table('device_locations')->insert($rows);

        // Update the device's "last known" snapshot from whichever point is
        // actually newest — a batch isn't guaranteed to arrive in order. This
        // is a cache of the thread's latest entry for quick status display,
        // never a substitute for the full per-point history above.
        $latest = collect($data['points'])->sortByDesc('recorded_at')->first();
        $device->last_seen_at = $now;
        $device->last_lat = $latest['lat'];
        $device->last_lng = $latest['lng'];
        $device->last_location_name = $placeNames[$this->coordKey($latest['lat'], $latest['lng'])] ?? $device->last_location_name;
        $device->last_location_at = \Carbon\Carbon::createFromTimestampMs($latest['recorded_at']);
        if (isset($latest['battery_pct'])) {
            $device->last_battery_pct = $latest['battery_pct'];
        }
        $device->save();

        return $this->success(['saved' => count($rows)], 'Locations saved.');
    }

    /** Pulled every sync cycle: current config + any pending commands (the MVP "Locate Now" path). */
    public function getConfig(Request $r, string $uuid)
    {
        $companyId = (int) $r->user()->company_id;
        $device = TrackedDevice::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('uuid', $uuid)->first();

        if (! $device) {
            return $this->error('Device not found. Register it first.', 404);
        }

        $device->last_seen_at = now();
        $device->save();

        $config = DeviceConfig::firstOrCreate(['device_id' => $device->id], self::DEFAULT_CONFIG);
        $pending = $device->pendingCommands()->orderBy('id')->get(['id', 'command']);

        return $this->success([
            'tracking_enabled' => (bool) $device->tracking_enabled,
            ...$this->configPayload($config),
            'pending_commands' => $pending,
        ]);
    }

    /**
     * The device pushes its own local settings changes here (e.g. a user
     * drags the interval slider in the app) so the server — edited from the
     * admin panel — stays the single source of truth for both sides instead
     * of the phone's local value just getting silently overwritten by the
     * next getConfig() pull.
     */
    public function updateConfig(Request $r, string $uuid)
    {
        $data = $r->validate([
            'tracking_enabled' => 'sometimes|boolean',
            'tracking_interval_seconds' => 'sometimes|integer|min:15|max:3600',
            'high_accuracy_mode' => 'sometimes|boolean',
            'min_distance_meters' => 'sometimes|integer|min:0|max:5000',
            'stationary_interval_seconds' => 'sometimes|nullable|integer|min:15|max:7200',
            'geocoding_enabled' => 'sometimes|boolean',
            'low_battery_threshold_pct' => 'sometimes|integer|min:1|max:50',
        ]);

        $companyId = (int) $r->user()->company_id;
        $device = TrackedDevice::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('uuid', $uuid)->first();

        if (! $device) {
            return $this->error('Device not found.', 404);
        }

        if (array_key_exists('tracking_enabled', $data)) {
            $device->tracking_enabled = $data['tracking_enabled'];
            $device->save();
        }

        $configFields = collect($data)->except('tracking_enabled')->toArray();
        $config = DeviceConfig::firstOrCreate(['device_id' => $device->id], self::DEFAULT_CONFIG);
        if (! empty($configFields)) {
            $config->update($configFields);
        }

        return $this->success([
            'tracking_enabled' => (bool) $device->tracking_enabled,
            ...$this->configPayload($config),
        ], 'Config updated.');
    }

    /** Device confirms it executed a command (e.g. took the on-demand fix). */
    public function ackCommand(Request $r, string $uuid, int $commandId)
    {
        $companyId = (int) $r->user()->company_id;
        $device = TrackedDevice::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('uuid', $uuid)->first();

        if (! $device) {
            return $this->error('Device not found.', 404);
        }

        $command = $device->commands()->where('id', $commandId)->first();
        if (! $command) {
            return $this->error('Command not found.', 404);
        }

        $command->status = 'executed';
        $command->executed_at = now();
        $command->save();

        return $this->success(null, 'Acknowledged.');
    }

    private function configPayload(DeviceConfig $config): array
    {
        return [
            'tracking_interval_seconds' => $config->tracking_interval_seconds,
            'high_accuracy_mode' => (bool) $config->high_accuracy_mode,
            'min_distance_meters' => $config->min_distance_meters,
            'stationary_interval_seconds' => $config->stationary_interval_seconds,
            'geocoding_enabled' => (bool) $config->geocoding_enabled,
            'low_battery_threshold_pct' => $config->low_battery_threshold_pct,
        ];
    }

    private function coordKey(float|string $lat, float|string $lng): string
    {
        return round((float) $lat, 4).','.round((float) $lng, 4);
    }

    /**
     * Resolves a place name for every unique coordinate in the batch,
     * cache-first. The newest point always gets a live lookup on a cache
     * miss (it drives the device's headline "last known location"); other
     * misses in the same batch are capped so one big catch-up push from a
     * device that was offline for a while can't turn into dozens of
     * sequential Nominatim calls — those stay null and are picked up by the
     * tracking:backfill-location-names scheduled command instead.
     *
     * @return array<string, string> coordKey => place name
     */
    private function resolvePlaceNames(array $points, GeocodingService $geocoding): array
    {
        $sorted = collect($points)->sortByDesc('recorded_at')->values();
        $latestKey = $this->coordKey($sorted[0]['lat'], $sorted[0]['lng']);

        $uniqueCoords = [];
        foreach ($sorted as $p) {
            $uniqueCoords[$this->coordKey($p['lat'], $p['lng'])] = [$p['lat'], $p['lng']];
        }
        // Resolve the latest point's coordinate first regardless of its
        // position in the batch, so it never gets skipped by the live-call cap.
        if (isset($uniqueCoords[$latestKey])) {
            $uniqueCoords = [$latestKey => $uniqueCoords[$latestKey]] + $uniqueCoords;
        }

        $names = [];
        $liveCallsUsed = 0;

        foreach ($uniqueCoords as $key => [$lat, $lng]) {
            $cached = $geocoding->cached((float) $lat, (float) $lng);
            if ($cached !== null) {
                $names[$key] = $cached;

                continue;
            }

            $isLatest = $key === $latestKey;
            if (! $isLatest && $liveCallsUsed >= self::MAX_LIVE_GEOCODES_PER_PUSH) {
                continue;
            }

            $resolved = $geocoding->resolveLive((float) $lat, (float) $lng);
            if (! $isLatest) {
                $liveCallsUsed++;
            }
            if ($resolved !== null) {
                $names[$key] = $resolved;
            }
        }

        return $names;
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceConfig;
use App\Models\TrackedDevice;
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

        $config = DeviceConfig::firstOrCreate(['device_id' => $device->id], ['tracking_interval_seconds' => 60, 'high_accuracy_mode' => true]);

        return $this->success([
            'device_id' => $device->uuid,
            'tracking_enabled' => $device->tracking_enabled,
            'config' => [
                'tracking_interval_seconds' => $config->tracking_interval_seconds,
                'high_accuracy_mode' => (bool) $config->high_accuracy_mode,
            ],
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

        $now = now();
        $rows = array_map(fn ($p) => [
            'company_id' => $companyId,
            'device_id' => $device->id,
            'lat' => $p['lat'],
            'lng' => $p['lng'],
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
        // actually newest — a batch isn't guaranteed to arrive in order.
        $latest = collect($data['points'])->sortByDesc('recorded_at')->first();
        $device->last_seen_at = $now;
        $device->last_lat = $latest['lat'];
        $device->last_lng = $latest['lng'];
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

        $config = DeviceConfig::firstOrCreate(['device_id' => $device->id], ['tracking_interval_seconds' => 60, 'high_accuracy_mode' => true]);
        $pending = $device->pendingCommands()->orderBy('id')->get(['id', 'command']);

        return $this->success([
            'tracking_enabled' => (bool) $device->tracking_enabled,
            'tracking_interval_seconds' => $config->tracking_interval_seconds,
            'high_accuracy_mode' => (bool) $config->high_accuracy_mode,
            'pending_commands' => $pending,
        ]);
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
}

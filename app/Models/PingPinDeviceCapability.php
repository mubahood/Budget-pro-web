<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The known capability vocabulary — deliberately matching the Dart
 * DeviceCapability enum (lib/domain/entities/device.dart) exactly, so
 * client and server can never drift into using different strings for the
 * same concept.
 */
class PingPinDeviceCapability extends Model
{
    use HasFactory;

    // Same Eloquent naming-convention gap as PingPinDeviceConsent — see that
    // model's comment.
    protected $table = 'pingpin_device_capabilities';

    const BACKGROUND_LOCATION = 'background_location';

    const REMOTE_RING = 'remote_ring';

    const REMOTE_LOCK = 'remote_lock';

    const REMOTE_WIPE = 'remote_wipe';

    const CAMERA_CAPTURE = 'camera_capture';

    const SIM_WATCH = 'sim_watch';

    const SMS_FALLBACK = 'sms_fallback';

    const UNINSTALL_GUARD = 'uninstall_guard';

    const ALL = [
        self::BACKGROUND_LOCATION, self::REMOTE_RING, self::REMOTE_LOCK, self::REMOTE_WIPE,
        self::CAMERA_CAPTURE, self::SIM_WATCH, self::SMS_FALLBACK, self::UNINSTALL_GUARD,
    ];

    protected $fillable = ['device_id', 'capability', 'supported', 'declared_at'];

    protected $casts = [
        'supported' => 'boolean',
        'declared_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(TrackedDevice::class, 'device_id');
    }

    /**
     * Default-allow when a device has never declared either way (every
     * device registered before this table existed, and every current
     * client build — capability declaration is Phase 4/6 client work not
     * shipped yet) — otherwise "Locate Now" and every other existing
     * command would break for the entire installed base today. Only an
     * EXPLICIT `supported: false` row is a real rejection. Mirrors the
     * consent gate's interim policy (DECISIONS.md D11/D12): fail open on
     * "unknown", fail closed on "positively declared unsupported".
     */
    public static function supports(int $deviceId, string $capability): bool
    {
        $row = static::where('device_id', $deviceId)->where('capability', $capability)->first();

        return $row === null || $row->supported;
    }
}

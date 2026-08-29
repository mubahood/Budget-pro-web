<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per consent event on a device (grant or the record of a revoke).
 * A device is allowed to report location only while it has at least one
 * row with `revoked_at` null (PLAN.md §3, brief §7's consent requirement
 * modelled as enforceable data rather than a UI-only checkbox).
 */
class PingPinDeviceConsent extends Model
{
    use HasFactory;

    // Eloquent's naming convention splits "PingPin" into two words ("ping",
    // "pin") and would guess `ping_pin_device_consents` — the real table
    // (and every other pingpin_* table in this schema) has no underscore
    // between the two, so this must be set explicitly.
    protected $table = 'pingpin_device_consents';

    protected $fillable = [
        'device_id', 'consented_by_user_id', 'consented_at', 'revoked_at', 'consent_text_version',
    ];

    protected $casts = [
        'consented_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(TrackedDevice::class, 'device_id');
    }

    public function consentedBy()
    {
        return $this->belongsTo(User::class, 'consented_by_user_id');
    }

    public static function isActiveFor(int $deviceId): bool
    {
        return static::where('device_id', $deviceId)->whereNull('revoked_at')->exists();
    }
}

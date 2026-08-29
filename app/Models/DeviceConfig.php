<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * No CompanyScope — always accessed through a TrackedDevice relation, which
 * is itself already company-scoped, so an extra scope here would just be
 * redundant (and this table has no company_id column of its own).
 */
class DeviceConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'tracking_interval_seconds',
        'high_accuracy_mode',
        'min_distance_meters',
        'stationary_interval_seconds',
        'geocoding_enabled',
        'low_battery_threshold_pct',
    ];

    protected $casts = [
        'high_accuracy_mode' => 'boolean',
        'geocoding_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(TrackedDevice::class, 'device_id');
    }
}

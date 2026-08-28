<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrackedDevice extends Model
{
    use AuditLogger, HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $casts = [
        'tracking_enabled' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_location_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function config()
    {
        return $this->hasOne(DeviceConfig::class, 'device_id');
    }

    public function locations()
    {
        return $this->hasMany(DeviceLocation::class, 'device_id');
    }

    public function commands()
    {
        return $this->hasMany(DeviceCommand::class, 'device_id');
    }

    public function pendingCommands()
    {
        return $this->commands()->where('status', 'pending');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

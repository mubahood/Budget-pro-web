<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * No CompanyScope — always accessed through a TrackedDevice relation, which
 * is itself already company-scoped.
 */
class DeviceCommand extends Model
{
    use HasFactory;

    const LOCATE_NOW = 'locate_now';

    protected $fillable = ['device_id', 'command', 'status', 'executed_at'];

    protected $casts = [
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(TrackedDevice::class, 'device_id');
    }
}

<?php

namespace App\Models;

use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide reference data (BACKEND_API_MASTER_TASKS.md §9) —
 * admin-authored, no company scoping. farm_type_slug is a value-FK to
 * PoultryFarmType::slug, not a foreign-key constraint, matching the mobile
 * client's own uuid-as-slug convention for farm types.
 */
class PoultryProductionGuideTask extends Model
{
    use AuditLogger, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'days_after_start' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function farmType()
    {
        return $this->belongsTo(PoultryFarmType::class, 'farm_type_slug', 'slug');
    }
}

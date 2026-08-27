<?php

namespace App\Models;

use App\Traits\AuditLogger;
use App\Traits\PoultryReferenceSyncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide reference data (BACKEND_API_MASTER_TASKS.md §9) —
 * admin-authored, no company scoping: every farm sees the same types.
 */
class PoultryFarmType extends Model
{
    use AuditLogger, HasFactory, PoultryReferenceSyncable;

    protected static string $syncUuidColumn = 'slug';

    protected static array $syncColumns = ['slug', 'name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function guideTasks()
    {
        return $this->hasMany(PoultryProductionGuideTask::class, 'farm_type_slug', 'slug');
    }
}

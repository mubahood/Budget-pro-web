<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a levy (a fixed amount for each member) or a harambee (members pledge freely). Rules live in App\Services\Unity. */
class UnityDrive extends Model
{
    use Syncable;

    protected $table = 'unity_drives';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'target_minor' => 'integer', 'per_member_minor' => 'integer', 'due_on' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

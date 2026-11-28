<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a decision taken at a meeting, perhaps with a member who owns it and a due date. Rules live in App\Services\Unity. */
class UnityResolution extends Model
{
    use Syncable;

    protected $table = 'unity_resolutions';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'due_on' => 'date:Y-m-d', 'done_on' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: one line of a member's history (joined, approved, transferred, exited...). Written once, never edited. Rules live in App\Services\Unity. */
class UnityMemberEvent extends Model
{
    use Syncable;

    protected $table = 'unity_member_events';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'on' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

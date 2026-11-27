<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a member serving on a committee with a title (ends_on null: still serving). Rules live in App\Services\Unity. */
class UnityCommitteeMember extends Model
{
    use Syncable;

    protected $table = 'unity_committee_members';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'sort_order' => 'integer', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

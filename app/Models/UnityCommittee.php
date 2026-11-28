<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a committee: of the whole group (chapter_id null) or of a chapter, with its titled officials. Rules live in App\Services\Unity. */
class UnityCommittee extends Model
{
    use Syncable;

    protected $table = 'unity_committees';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

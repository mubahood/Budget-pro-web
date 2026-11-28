<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: one member's vote on one question. Never shown with the member: results are counts only. Rules live in App\Services\Unity. */
class UnityVote extends Model
{
    use Syncable;

    protected $table = 'unity_votes';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'cast_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

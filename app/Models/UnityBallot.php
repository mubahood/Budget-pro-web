<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: an election or a poll; the vote is secret (only counts are ever shown). Rules live in App\Services\Unity. */
class UnityBallot extends Model
{
    use Syncable;

    protected $table = 'unity_ballots';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'opens_at' => 'datetime', 'closes_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

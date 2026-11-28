<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a position (election) or a question (poll) on a ballot. Rules live in App\Services\Unity. */
class UnityBallotQuestion extends Model
{
    use Syncable;

    protected $table = 'unity_ballot_questions';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

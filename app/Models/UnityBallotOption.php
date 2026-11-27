<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a candidate or an answer to a ballot question. Rules live in App\Services\Unity. */
class UnityBallotOption extends Model
{
    use Syncable;

    protected $table = 'unity_ballot_options';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

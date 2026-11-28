<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a member's request for welfare support and what was decided and paid. Rules live in App\Services\Unity. */
class UnityWelfareRequest extends Model
{
    use Syncable;

    protected $table = 'unity_welfare_requests';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'requested_minor' => 'integer', 'approved_minor' => 'integer', 'decided_on' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

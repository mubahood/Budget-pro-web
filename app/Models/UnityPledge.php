<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: what one member pledged to a drive and has paid of it (minor units). Rules live in App\Services\Unity. */
class UnityPledge extends Model
{
    use Syncable;

    protected $table = 'unity_pledges';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'pledged_minor' => 'integer', 'paid_minor' => 'integer', 'due_on' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

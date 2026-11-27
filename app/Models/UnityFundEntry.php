<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: one line of the welfare fund book: money in or out (minor units, always above zero). Rules live in App\Services\Unity. */
class UnityFundEntry extends Model
{
    use Syncable;

    protected $table = 'unity_fund_entries';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'amount_minor' => 'integer', 'on' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A public holiday in one country (AK-51): a platform row, the same for every SACCO there, so it has no company_id.
 * Written only by App\Services\Sacco\Calendar\PublicHolidays.
 */
class SaccoPublicHoliday extends Model
{
    public const KINDS = ['fixed', 'movable'];

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date:Y-m-d', 'is_estimate' => 'boolean'];
}

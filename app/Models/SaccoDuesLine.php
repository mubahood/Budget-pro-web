<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One person's dues for one period of a programme (sacco:dues-prepare). */
class SaccoDuesLine extends Model
{
    protected $table = 'sacco_dues_lines';

    protected $guarded = ['id'];

    protected $casts = ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'expected_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

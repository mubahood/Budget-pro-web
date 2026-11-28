<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** Contributions (dues): what people are asked to give, recurring or once. Totals come from the payments (DuesService). */
class SaccoDuesProgramme extends Model
{
    protected $table = 'sacco_dues_programmes';

    protected $guarded = ['id'];

    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'closed_at' => 'datetime', 'allow_advance' => 'boolean',
        'amount_minor' => 'integer', 'target_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

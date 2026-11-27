<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** Money received for a programme. Never edited or deleted: a reversal is another row with the negative amount. */
class SaccoDuesPayment extends Model
{
    protected $table = 'sacco_dues_payments';

    protected $guarded = ['id'];

    protected $casts = ['paid_on' => 'date:Y-m-d', 'reversed_at' => 'datetime', 'amount_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * A return the SACCO owes (P4.2, P4.5): its form and period, when it is due, its figures as last generated and when it
 * was filed. A filed return keeps its figures; filing again is refused.
 */
class SaccoReturnFiling extends Model
{
    use AuditLogger;

    public const STATUSES = ['due', 'generated', 'filed'];

    protected $guarded = ['id'];

    protected $casts = ['figures' => 'array', 'file_ids' => 'array', 'reminded' => 'array', 'period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d',
        'due_on' => 'date:Y-m-d', 'filed_on' => 'date:Y-m-d', 'generated_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $f) {
            if ($f->getOriginal('status') === 'filed' && array_diff(array_keys($f->getDirty()), ['reminded', 'updated_at']) !== []) {
                throw BusinessRuleException::make('return_filed', 'This return is filed: its figures stay as they were sent.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('return_filed', 'A return is never deleted.');
        });
    }
}

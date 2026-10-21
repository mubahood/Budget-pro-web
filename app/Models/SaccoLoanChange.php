<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** A loan's history entry (a reschedule, top-up, write-off, recovery, class change): written once, never changed. */
class SaccoLoanChange extends Model
{
    public const KINDS = ['reschedule', 'top_up', 'write_off', 'recovery', 'cancellation', 'upgrade', 'downgrade', 'waiver'];

    protected $guarded = ['id'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'effective_on' => 'date:Y-m-d', 'borrower_consented_at' => 'datetime', 'amount_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('loan_history', 'A loan\'s history is never edited.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('loan_history', 'A loan\'s history is never deleted.');
        });
    }
}

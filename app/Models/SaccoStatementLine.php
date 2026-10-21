<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One line of an imported statement (P3.4): what it said never changes; only its match moves. */
class SaccoStatementLine extends Model
{
    public const STATUSES = ['unmatched', 'proposed', 'matched', 'ignored'];

    /** What the statement said: fixed once imported. */
    private const FIXED = ['company_id', 'import_id', 'line_no', 'line_date', 'reference', 'description', 'amount_minor', 'balance_minor'];

    protected $guarded = ['id'];

    protected $casts = ['line_date' => 'date:Y-m-d', 'amount_minor' => 'integer', 'balance_minor' => 'integer', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $l) {
            foreach (self::FIXED as $column) {
                if ($l->isDirty($column)) {
                    throw BusinessRuleException::make('immutable_event', 'A statement line says what the statement said: import a new statement instead.');
                }
            }
        });
    }
}

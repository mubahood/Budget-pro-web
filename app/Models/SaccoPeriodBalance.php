<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's line of a closing snapshot (ADR 0009 §8): written once at close with the head hash (the anchor),
 * then never changed. A reopened period that closes again writes a new snapshot beside this one.
 */
class SaccoPeriodBalance extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['opening_minor' => 'integer', 'debits_minor' => 'integer', 'credits_minor' => 'integer', 'closing_minor' => 'integer', 'snapshot_no' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('ledger_immutable', 'A closing snapshot cannot be changed.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('ledger_immutable', 'A closing snapshot cannot be deleted.');
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SaccoPeriod::class, 'period_id');
    }
}

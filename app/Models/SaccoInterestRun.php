<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A savings interest run (P4.6, 04 §3.6): one product's accrual for a month, or its credit for a posting period.
 * Written once by App\Services\Sacco\YearEnd\SavingsInterest; only its entry id is filled in after the posting.
 */
class SaccoInterestRun extends Model
{
    public const KINDS = ['accrual', 'credit'];

    protected $guarded = ['id'];

    protected $casts = ['period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'rate_bp' => 'integer', 'accounts_count' => 'integer', 'total_minor' => 'integer',
        'released_minor' => 'integer', 'product_id' => 'integer', 'entry_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $r) {
            if (array_diff(array_keys($r->getDirty()), ['entry_id', 'updated_at']) !== [] || $r->getOriginal('entry_id') !== null) {
                throw BusinessRuleException::make('interest_run_final', 'An interest run is final: a mistake is corrected by a reversal.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('interest_run_final', 'An interest run is never deleted.');
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaccoInterestRunLine::class, 'run_id');
    }
}

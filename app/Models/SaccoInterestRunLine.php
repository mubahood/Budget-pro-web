<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One savings account's share of an interest run (P4.6). An accrual line is credited (or released) once, never changed otherwise. */
class SaccoInterestRunLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['basis_minor' => 'integer', 'days' => 'integer', 'amount_minor' => 'integer', 'released' => 'boolean', 'run_id' => 'integer',
        'savings_account_id' => 'integer', 'member_id' => 'integer', 'credited_run_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $l) {
            if (array_diff(array_keys($l->getDirty()), ['credited_run_id', 'released', 'updated_at']) !== [] || $l->getOriginal('credited_run_id') !== null) {
                throw BusinessRuleException::make('interest_run_final', 'An interest line is final once credited.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('interest_run_final', 'An interest line is never deleted.');
        });
    }
}

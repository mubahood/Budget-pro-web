<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One step of a loan request (Simple\LoanFlow): who, when, and their note. Written once. */
class SaccoLoanStep extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['loan_id' => 'integer', 'actor_user_id' => 'integer', 'actor_member_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('step_final', 'A loan step is never changed.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('step_final', 'A loan step is never deleted.');
        });
    }
}

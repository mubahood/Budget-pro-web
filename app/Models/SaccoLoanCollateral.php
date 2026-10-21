<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/** Security registered against a loan (SI 108 reg. 8): written by Loans\Collateral only; released, never deleted. */
class SaccoLoanCollateral extends Model
{
    use AuditLogger;

    protected $table = 'sacco_loan_collateral';

    protected $guarded = ['id'];

    protected $casts = ['demand_notice_on' => 'date:Y-m-d', 'realised_on' => 'date:Y-m-d', 'released_on' => 'date:Y-m-d', 'value_minor' => 'integer', 'realised_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::deleting(function () {
            throw BusinessRuleException::make('collateral_history', 'Collateral is released, never deleted: the register keeps it.');
        });
    }
}

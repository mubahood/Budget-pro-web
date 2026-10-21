<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One member's dividend (P4.8): its basis and amount never change; only how far its payment got moves. */
class SaccoDividendLine extends Model
{
    public const STATUSES = ['due', 'paid', 'sent', 'failed'];

    protected $guarded = ['id'];

    protected $casts = ['basis_minor' => 'integer', 'amount_minor' => 'integer', 'paid_on' => 'date:Y-m-d', 'run_id' => 'integer', 'member_id' => 'integer',
        'savings_account_id' => 'integer', 'payout_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $l) {
            if ($l->isDirty(['basis_minor', 'amount_minor', 'member_id', 'run_id', 'company_id'])) {
                throw BusinessRuleException::make('dividend_final', 'A member\'s dividend is final once the run is approved.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('dividend_final', 'A dividend line is never deleted.');
        });
    }
}

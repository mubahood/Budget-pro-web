<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** A step of a dormant account's timeline (P4.10): on the register, notice given, advertised, transferred. Never changed. */
class SaccoDormancyNotice extends Model
{
    public const STAGES = ['register', 'notice', 'advertised', 'transferred'];

    protected $guarded = ['id'];

    protected $casts = ['on' => 'date:Y-m-d', 'balance_minor' => 'integer', 'savings_account_id' => 'integer', 'member_id' => 'integer', 'entry_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('dormancy_final', 'A step of the dormancy timeline is a record: add the next step instead.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('dormancy_final', 'A step of the dormancy timeline is never deleted.');
        });
    }
}

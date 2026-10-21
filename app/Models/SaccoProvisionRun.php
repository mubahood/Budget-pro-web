<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One month's loan-loss provisioning run (AK-40): written once by Loans\Provisioning, never changed. */
class SaccoProvisionRun extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['by_class' => 'array', 'required_minor' => 'integer', 'previous_minor' => 'integer', 'delta_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('provision_final', 'A provisioning run is final: next month\'s run moves the allowance again.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('provision_final', 'A provisioning run is never deleted.');
        });
    }
}

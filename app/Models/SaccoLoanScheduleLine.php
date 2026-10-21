<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * One instalment of one schedule version (04 §3.4). The due figures are fixed when the version is made; the paid
 * columns are allocation caches (Loans\Repayments); a reschedule marks the old lines superseded and keeps them.
 */
class SaccoLoanScheduleLine extends Model
{
    public const STATUSES = ['due', 'partial', 'paid', 'superseded'];

    /** Fixed once the line exists. */
    private const FIXED = ['loan_id', 'schedule_version', 'installment_no', 'due_on', 'opening_principal_minor', 'principal_due_minor', 'interest_due_minor'];

    protected $guarded = ['id'];

    protected $casts = ['due_on' => 'date:Y-m-d', 'paid_on' => 'date:Y-m-d', 'interest_suspended' => 'boolean', 'opening_principal_minor' => 'integer',
        'principal_due_minor' => 'integer', 'interest_due_minor' => 'integer', 'interest_charged_minor' => 'integer', 'fee_due_minor' => 'integer',
        'principal_paid_minor' => 'integer', 'interest_paid_minor' => 'integer', 'fee_paid_minor' => 'integer', 'installment_no' => 'integer', 'schedule_version' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $line) {
            foreach (self::FIXED as $column) {
                if ($line->isDirty($column)) {
                    throw BusinessRuleException::make('schedule_fixed', 'A schedule line is fixed once made: reschedule for a new version instead.');
                }
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('schedule_fixed', 'Schedule lines are superseded, never deleted.');
        });
    }

    /** What this line asks for: the interest as charged once due, as scheduled before. */
    public function interestOwedMinor(): int
    {
        return (int) ($this->interest_charged_minor ?? $this->interest_due_minor);
    }
}

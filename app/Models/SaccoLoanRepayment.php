<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * How one payment into a loan was split (AK-27: penalty, fees, interest, principal; the rest to savings), beside the
 * journal entry that posted it. Written by Loans\Repayments in the posting transaction; only its status moves
 * (posted → reversed, with the reversal entry), never its figures.
 */
class SaccoLoanRepayment extends Model
{
    public const KINDS = ['repayment', 'guarantor_offset', 'top_up_settlement', 'cancellation'];

    public const CHANNELS = ['cash', 'bank', 'savings', 'momo', 'guarantor', 'internal'];

    private const MOVABLE = ['status', 'reversal_entry_id', 'transaction_id', 'updated_at'];

    protected $guarded = ['id'];

    protected $casts = ['value_date' => 'date:Y-m-d', 'amount_minor' => 'integer', 'penalty_minor' => 'integer', 'penalty_suspense_minor' => 'integer',
        'fees_minor' => 'integer', 'interest_minor' => 'integer', 'interest_suspense_minor' => 'integer', 'interest_to_day_minor' => 'integer',
        'principal_minor' => 'integer', 'overpayment_minor' => 'integer', 'schedule_version' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $r) {
            if (array_diff(array_keys($r->getDirty()), self::MOVABLE) !== []) {
                throw BusinessRuleException::make('immutable_event', 'A repayment is reversed, never edited.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('immutable_event', 'A repayment is reversed, never deleted.');
        });
    }
}

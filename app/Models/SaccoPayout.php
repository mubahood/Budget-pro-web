<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One payout to a member's wallet (04 §11.2): written by App\Services\Sacco\Payouts\PayoutService only. */
class SaccoPayout extends Model
{
    public const STATUSES = ['queued', 'sent', 'successful', 'failed', 'unknown', 'reversed'];

    public const PURPOSES = ['loan_disbursement', 'withdrawal', 'shareout', 'dividend', 'exit'];

    /** Fixed once written. */
    private const FIXED = ['company_id', 'uuid', 'purpose', 'subject_type', 'subject_id', 'member_id', 'phone_e164', 'amount_minor', 'currency', 'idempotency_key'];

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'fee_minor' => 'integer', 'attempts' => 'integer', 'sent_at' => 'datetime', 'resolved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $p) {
            foreach (self::FIXED as $column) {
                if ($p->isDirty($column)) {
                    throw BusinessRuleException::make('immutable_event', 'A payout is never changed: it settles, fails or is reversed.');
                }
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('immutable_event', 'A payout is never deleted.');
        });
    }
}

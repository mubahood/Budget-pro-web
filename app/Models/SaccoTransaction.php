<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The money document behind a member-facing posting (04 §3.3, ADR 0001 event): a deposit, a withdrawal, a share
 * purchase… Written by App\Services\Sacco\SaccoTransactions. What it records (kind, amount, member, accounts, value
 * date) never changes once the row exists; only the workflow moves, forward: status, hold_reason, journal_entry_id
 * (set once), approval_id, and for a held receipt the account it is finally allocated to (set once, from empty).
 * A correction is a reversal row (`reverses_id`, unique).
 */
class SaccoTransaction extends Model
{
    use Syncable;

    public const KINDS = ['deposit', 'withdrawal', 'share_purchase', 'share_transfer', 'entrance_fee', 'fee', 'internal_transfer',
        'loan_disbursement', 'loan_repayment', 'loan_cancellation', 'fine_payment', 'fund_contribution', 'reversal'];

    public const CHANNELS = ['cash', 'bank', 'momo', 'checkoff', 'internal'];

    public const STATUSES = ['posted', 'held', 'pending_approval', 'rejected', 'cancelled'];

    /** Where each status may go next. */
    public const NEXT = ['pending_approval' => ['posted', 'rejected', 'cancelled'], 'held' => ['posted'], 'posted' => [], 'rejected' => [], 'cancelled' => []];

    /** Fixed once written. */
    public const FROZEN = ['kind', 'channel', 'amount_minor', 'fee_minor', 'currency', 'value_date', 'company_id', 'uuid', 'reverses_id', 'counter_account_id',
        'shares_count', 'loan_id', 'device_receipt_no'];

    /** Fixed once set (a held receipt learns its member and account when it is allocated; a withdrawal gets its receipt number when it is paid). */
    public const SET_ONCE = ['member_id', 'savings_account_id', 'share_account_id', 'journal_entry_id', 'receipt_no'];

    protected $guarded = ['id'];

    protected $casts = ['value_date' => 'date:Y-m-d', 'amount_minor' => 'integer', 'fee_minor' => 'integer', 'shares_count' => 'integer', 'is_deleted' => 'boolean',
        'meta' => 'array', 'member_id' => 'integer', 'savings_account_id' => 'integer', 'share_account_id' => 'integer', 'journal_entry_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $t) {
            foreach (self::FROZEN as $column) {
                if ($t->isDirty($column)) {
                    throw BusinessRuleException::make('immutable_event', 'A receipt cannot be changed once recorded: reverse it and record it again.');
                }
            }
            foreach (self::SET_ONCE as $column) {
                if ($t->isDirty($column) && $t->getOriginal($column) !== null) {
                    throw BusinessRuleException::make('immutable_event', 'A receipt cannot be changed once recorded: reverse it and record it again.');
                }
            }
            if ($t->isDirty('status') && ! in_array($t->status, self::NEXT[$t->getOriginal('status')] ?? [], true)) {
                throw BusinessRuleException::make('immutable_event', "This receipt is {$t->getOriginal('status')}.");
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('immutable_event', 'A receipt is never deleted: reverse it instead.');
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SaccoSavingsAccount::class, 'savings_account_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SaccoJournalEntry::class, 'journal_entry_id');
    }
}

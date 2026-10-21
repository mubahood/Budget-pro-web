<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's savings account (04 §3.3), a row of the savings sub-ledger under its product's control account (ADR
 * 0009 §7). Opened, frozen and closed by App\Services\Sacco\Savings\SavingsAccounts; its balance_minor is a cache
 * that only PostingService moves (SavingsLedger::project), so an Eloquent save that changes it is refused.
 */
class SaccoSavingsAccount extends Model
{
    use AuditLogger, Syncable;

    public const STATUSES = ['active', 'dormant', 'frozen', 'closed'];

    protected $guarded = ['id'];

    protected $casts = ['opened_on' => 'date:Y-m-d', 'closed_on' => 'date:Y-m-d', 'maturity_on' => 'date:Y-m-d', 'dormant_since' => 'date:Y-m-d',
        'last_activity_on' => 'date:Y-m-d', 'balance_minor' => 'integer', 'held_minor' => 'integer', 'is_deleted' => 'boolean',
        'member_id' => 'integer', 'product_id' => 'integer', 'ledger_account_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $a) {
            if ($a->isDirty(['balance_minor', 'member_id', 'product_id', 'ledger_account_id', 'account_no', 'company_id'])) {
                throw BusinessRuleException::make('ledger_immutable', 'An account\'s owner, product and balance move only through the books.');
            }
            if ($a->getOriginal('status') === 'closed' && $a->isDirty('status')) {
                throw BusinessRuleException::make('account_closed', 'A closed account stays closed: open a new one.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('account_history', 'A savings account is never deleted: close it instead.');
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SaccoSavingsProduct::class, 'product_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }
}

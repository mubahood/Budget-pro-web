<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's share account (04 §3.3): the shares sub-ledger row under 3100. Its balance moves only through the
 * books (SharesLedger::project) and its share count only through ShareService, so an Eloquent save that changes
 * either is refused.
 */
class SaccoShareAccount extends Model
{
    use AuditLogger, Syncable;

    protected $guarded = ['id'];

    protected $casts = ['opened_on' => 'date:Y-m-d', 'shares_count' => 'integer', 'balance_minor' => 'integer', 'is_deleted' => 'boolean', 'member_id' => 'integer',
        'ledger_account_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $a) {
            if ($a->isDirty(['balance_minor', 'shares_count', 'member_id', 'ledger_account_id', 'account_no', 'company_id'])) {
                throw BusinessRuleException::make('ledger_immutable', 'Shares move only through the books.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('account_history', 'A share account is never deleted.');
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }
}

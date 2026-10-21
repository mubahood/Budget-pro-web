<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One account of a SACCO's chart (ADR 0009 §3). Seeded by ChartOfAccounts::seed, added through an approval,
 * never deleted: an account the code posts to (system_key) is never deactivated either.
 */
class SaccoLedgerAccount extends Model
{
    use AuditLogger;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    public const SIDES = ['debit', 'credit'];

    /** Sub-ledger control accounts and the sub_type their lines carry (ADR 0009 §4.2, AK-55). */
    public const CONTROLS = ['savings' => 'savings', 'shares' => 'shares', 'loan_principal' => 'loan', 'loan_interest' => 'loan',
        'loan_penalty' => 'loan', 'loan_fees' => 'loan', 'fines' => 'fine', 'fund' => 'fund_due'];

    protected $guarded = ['id'];

    protected $casts = ['is_postable' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $account) {
            if ($account->getOriginal('system_key') !== null && ($account->isDirty('system_key') || ($account->isDirty('is_active') && ! $account->is_active))) {
                throw BusinessRuleException::make('system_account', "{$account->code} {$account->name} is used by the books themselves: it stays active and keeps its key.");
            }
            foreach (['code', 'type', 'normal_side', 'control_of'] as $fixed) {
                if ($account->isDirty($fixed) && self::hasLines((int) $account->id)) {
                    throw BusinessRuleException::make('account_in_use', "{$account->code} has postings: its {$fixed} cannot change.");
                }
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('ledger_immutable', 'An account is never deleted. Deactivate it instead.');
        });
    }

    public static function hasLines(int $accountId): bool
    {
        return SaccoJournalLine::withoutGlobalScopes()->where('ledger_account_id', $accountId)->exists();
    }

    /** The sub_type a line on this account must carry, or null for an ordinary account. */
    public function subType(): ?string
    {
        return $this->control_of === null ? null : (self::CONTROLS[$this->control_of] ?? null);
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_side === 'debit';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function balance(): HasOne
    {
        return $this->hasOne(SaccoAccountBalance::class, 'ledger_account_id');
    }
}

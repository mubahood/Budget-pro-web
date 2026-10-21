<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A savings product (docs/akiba/04-architecture.md §3.3): its terms and its own control account (2110, 2120, 2130…).
 * Written by App\Services\Sacco\Savings\SavingsProducts; the terms move only through a new version (`terms_version`,
 * the old terms kept in sacco_savings_product_versions). Pulled by the phone (Syncable), never written by it.
 */
class SaccoSavingsProduct extends Model
{
    use AuditLogger, Syncable;

    public const KINDS = ['voluntary', 'compulsory', 'fixed', 'group'];

    public const INTEREST_BASES = ['none', 'daily_balance', 'min_monthly_balance'];

    public const INTEREST_POSTINGS = ['monthly', 'quarterly', 'annually'];

    /** The fields that make up the product's terms (a change is a new version, behind `product_terms_change`). */
    public const TERMS = ['withdrawable', 'min_opening_minor', 'min_balance_minor', 'interest_rate_bp', 'interest_basis', 'interest_posting', 'term_months',
        'early_withdrawal_penalty_bp', 'withdrawal_fee_minor', 'max_withdrawals_per_month', 'notice_days', 'disclosure_text'];

    protected $guarded = ['id'];

    protected $casts = ['withdrawable' => 'boolean', 'is_active' => 'boolean', 'is_deleted' => 'boolean', 'min_opening_minor' => 'integer', 'min_balance_minor' => 'integer',
        'interest_rate_bp' => 'integer', 'term_months' => 'integer', 'early_withdrawal_penalty_bp' => 'integer', 'withdrawal_fee_minor' => 'integer',
        'max_withdrawals_per_month' => 'integer', 'notice_days' => 'integer', 'terms_version' => 'integer', 'ledger_account_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $p) {
            if ($p->isDirty(['ledger_account_id', 'code', 'kind', 'company_id'])) {
                throw BusinessRuleException::make('product_fixed', 'A product\'s code, kind and control account never change: make a new product instead.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('product_history', 'A savings product is never deleted: switch it off instead.');
        });
    }

    /** @return array<string, mixed> the terms as they stand */
    public function terms(): array
    {
        return array_intersect_key($this->attributesToArray(), array_flip(self::TERMS));
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(SaccoSavingsAccount::class, 'product_id');
    }
}

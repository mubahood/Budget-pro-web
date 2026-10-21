<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One posted journal entry (ADR 0009 §4, §5). Inserted only by App\Services\Sacco\Ledger\PostingService and never
 * updated or deleted: a correction is a reversal entry (ReversalService), so every mistake stays visible.
 */
class SaccoJournalEntry extends Model
{
    public const UPDATED_AT = null;

    /** The kinds of entry the books know (PostingRules builds most of them). */
    public const KINDS = ['deposit', 'withdrawal', 'fee', 'share_purchase', 'share_transfer', 'entrance_fee', 'internal_transfer',
        'held_receipt', 'held_receipt_allocation', 'opening_balance', 'manual', 'reversal',
        'loan_disbursement', 'loan_repayment', 'loan_cancellation', 'interest_due', 'penalty', 'provision', 'write_off', 'recovery',
        'fine', 'fine_payment', 'fund_contribution', 'savings_interest', 'year_end', 'appropriation', 'dividend', 'shareout', 'payout', 'till_variance',
        'stf_buyback', 'dormant_transfer'];

    protected $guarded = ['id'];

    protected $casts = ['entry_date' => 'date:Y-m-d', 'value_date' => 'date:Y-m-d', 'total_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('ledger_immutable', 'A posted journal entry cannot be changed. Reverse it and post the right one.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('ledger_immutable', 'A posted journal entry cannot be deleted. Reverse it instead.');
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaccoJournalLine::class, 'journal_entry_id')->orderBy('line_no');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SaccoPeriod::class, 'period_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }
}

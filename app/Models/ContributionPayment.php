<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One payment received against a pledge (roadmap 02 §6). Append-only: written only by
 * App\Services\Budget\ContributionPayments, never edited or deleted; a mistake gets a reversal row
 * (negative amount, reverses_id, reason). The pledge's paid_amount is the sum of its rows.
 */
class ContributionPayment extends Model
{
    use Syncable;

    public const METHODS = ['cash', 'mobile_money', 'bank', 'card', 'cheque', 'other'];

    public const SOURCES = ['payment', 'reversal', 'opening', 'pledge_edit'];

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date:Y-m-d',
        'is_deleted' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('immutable_payment', 'A payment cannot be changed. Reverse it and record it again.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('immutable_payment', 'A payment cannot be deleted. Reverse it instead.');
        });
    }

    public function contributionRecord(): BelongsTo
    {
        return $this->belongsTo(ContributionRecord::class, 'contribution_record_id');
    }

    public function budgetProgram(): BelongsTo
    {
        return $this->belongsTo(BudgetProgram::class, 'budget_program_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }
}

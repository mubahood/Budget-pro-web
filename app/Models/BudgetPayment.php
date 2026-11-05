<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment out of an event's money (the review's PB-13). Append-only: written only by App\Services\Budget\BudgetPayments, never edited or deleted;
 * a mistake gets a reversal row (negative amount, reverses_id, reason). A budget line's invested_amount is the sum of its rows. paid_by_id handed
 * the money over, approved_by_id agreed it, created_by_id recorded it.
 */
class BudgetPayment extends Model
{
    use Syncable;

    public const SOURCES = ['payment', 'reversal', 'opening', 'item_edit', 'moved'];

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
            throw BusinessRuleException::make('immutable_payment', 'A payment out cannot be changed. Reverse it and record it again.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('immutable_payment', 'A payment out cannot be deleted. Reverse it instead.');
        });
    }

    public function budgetItem(): BelongsTo
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }

    public function budgetProgram(): BelongsTo
    {
        return $this->belongsTo(BudgetProgram::class, 'budget_program_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}

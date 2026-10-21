<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a journal entry (ADR 0009 §4.2): exactly one of debit_minor and credit_minor is above zero, and a
 * line on a control account names its sub-ledger row (sub_type, sub_id), so a member's statement is a query
 * over these lines. Inserted only by PostingService; never updated or deleted.
 */
class SaccoJournalLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['entry_date' => 'date:Y-m-d', 'value_date' => 'date:Y-m-d', 'debit_minor' => 'integer', 'credit_minor' => 'integer', 'line_no' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('ledger_immutable', 'A posted journal line cannot be changed. Reverse the entry instead.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('ledger_immutable', 'A posted journal line cannot be deleted. Reverse the entry instead.');
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SaccoJournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SaccoLedgerAccount::class, 'ledger_account_id');
    }
}

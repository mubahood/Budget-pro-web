<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account's balance cache (ADR 0009 §7): the sum of its journal lines, kept by PostingService under the row
 * lock in the posting's transaction, and checked against the lines by LedgerVerifier. balance_minor is signed,
 * positive on the account's normal side.
 */
class SaccoAccountBalance extends Model
{
    public const CREATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['debit_total_minor' => 'integer', 'credit_total_minor' => 'integer', 'balance_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SaccoLedgerAccount::class, 'ledger_account_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Money received against a sale (several rows = split payment). Ledger income
 * is posted per payment, not per sale (DECISIONS.md G5).
 */
class Payment extends Model
{
    public const METHODS = ['cash', 'mobile_money', 'card', 'bank', 'credit', 'cheque', 'other'];

    protected $fillable = [
        'client_uuid', 'company_id', 'sale_record_id', 'method', 'provider', 'reference', 'amount', 'currency',
        'received_at', 'received_by_id', 'financial_record_id', 'is_reversal', 'reverses_id', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'datetime',
        'is_reversal' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    /** Accept the legacy display labels ("Mobile Money", "Credit Card"...) and normalise. */
    public static function normalizeMethod(?string $raw): string
    {
        $v = strtolower(trim((string) $raw));
        $v = str_replace([' ', '-'], '_', $v);

        return match ($v) {
            '', 'cash' => 'cash',
            'mobile_money', 'momo', 'mtn', 'airtel', 'mpesa', 'm_pesa' => 'mobile_money',
            'card', 'credit_card', 'debit_card', 'visa', 'mastercard' => 'card',
            'bank', 'bank_transfer', 'transfer', 'eft' => 'bank',
            'credit', 'on_credit', 'debt' => 'credit',
            'cheque', 'check' => 'cheque',
            default => 'other',
        };
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(SaleRecord::class, 'sale_record_id');
    }

    public function financialRecord(): BelongsTo
    {
        return $this->belongsTo(FinancialRecord::class, 'financial_record_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }
}

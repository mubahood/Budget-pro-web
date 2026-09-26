<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received against a sale (several rows = split payment). Ledger income
 * is posted per payment, not per sale (DECISIONS.md G5).
 */
class Payment extends Model
{
    use Syncable;

    public const METHODS = ['cash', 'mobile_money', 'card', 'bank', 'credit', 'cheque', 'other'];

    protected $fillable = [
        'client_uuid', 'company_id', 'sale_record_id', 'customer_id', 'shift_id', 'method', 'provider', 'reference', 'amount', 'currency',
        'received_at', 'received_by_id', 'financial_record_id', 'is_reversal', 'reverses_id', 'notes',
        'uuid', 'device_id', 'created_by_uuid', 'client_created_at', 'client_updated_at',
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
    /** "mobile_money" → "Mobile money" (the shop's method labels), for statements and receipts. */
    public static function label(string $method): string
    {
        $key = self::normalizeMethod($method);

        return (string) (config('onboarding.payment_methods')[$key] ?? ucfirst(str_replace('_', ' ', $key)));
    }

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

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
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

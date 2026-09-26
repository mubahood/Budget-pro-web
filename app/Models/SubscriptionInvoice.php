<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A billing invoice raised against a company's subscription.
 */
class SubscriptionInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'subscription_id', 'number', 'amount', 'currency', 'status',
        'provider', 'provider_invoice_id', 'period_start', 'period_end', 'paid_at', 'meta',
        'tax_amount', 'tax_rate', 'refunded_at', 'refund_amount', 'abandoned_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'paid_at' => 'datetime',
        'tax_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'refunded_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'abandoned_at' => 'datetime',
        'meta' => 'array',
    ];

    /** pending → paid | failed | abandoned (never paid within 72 h); paid → refunded. */
    public const STATUSES = ['pending', 'paid', 'failed', 'abandoned', 'refunded'];

    public function plan(): ?Plan
    {
        $id = (int) data_get($this->meta, 'plan_id');

        return $id ? Plan::find($id) : null;
    }

    public function interval(): string
    {
        return data_get($this->meta, 'interval') === 'year' ? 'year' : 'month';
    }

    /** Paid or refunded invoices have a document the owner can download. */
    public function hasDocument(): bool
    {
        return in_array($this->status, ['paid', 'refunded'], true);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}

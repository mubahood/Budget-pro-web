<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PingPinSubscriptionInvoice extends Model
{
    use HasFactory;

    protected $table = 'pingpin_subscription_invoices';

    protected $fillable = [
        'company_id', 'subscription_id', 'number', 'amount', 'currency', 'status',
        'provider', 'provider_invoice_id', 'period_start', 'period_end', 'paid_at', 'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'paid_at' => 'datetime',
        'meta' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function subscription()
    {
        return $this->belongsTo(PingPinSubscription::class, 'subscription_id');
    }
}

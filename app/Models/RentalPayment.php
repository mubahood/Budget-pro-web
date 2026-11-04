<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Money a tenant handed over (or a deposit used, a waiver, credit brought in by an import), split over their bills by
 * allocations. Never edited: a mistake is a reversal.
 */
class RentalPayment extends Model
{
    use RentalRecord;

    /** What a posted row may still change: its caches and its reversal (RentalRecord). */
    public const MUTABLE = ['unallocated_minor', 'reversed_at', 'reversed_by_id', 'reverse_reason', 'receipt_snapshot'];

    protected $table = 'rental_payments';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'unallocated_minor' => 'integer', 'paid_on' => 'date:Y-m-d', 'reversed_at' => 'datetime', 'receipt_snapshot' => 'array'];

    /** Methods that bring money into the account's cash. */
    public const CASH_METHODS = ['cash', 'bank', 'mobile_money', 'cheque'];

    public const METHODS = ['cash', 'bank', 'mobile_money', 'cheque', 'deposit', 'waiver', 'opening'];

    public function movesCash(): bool
    {
        return in_array($this->method, self::CASH_METHODS, true);
    }

    public function lease()
    {
        return $this->belongsTo(RentalLease::class, 'lease_id');
    }

    public function tenant()
    {
        return $this->belongsTo(RentalTenant::class, 'tenant_id');
    }

    public function allocations()
    {
        return $this->hasMany(RentalAllocation::class, 'payment_id');
    }
}

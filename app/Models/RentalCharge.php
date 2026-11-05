<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A bill line: what a tenant owes for a period or an event. A memo for the journal: money moves only when it is paid
 * (ADR 0010 §4). Its amount never changes; it is voided only while nothing is paid on it.
 */
class RentalCharge extends Model
{
    use RentalRecord;

    /**
     * What a posted row may still change: its caches and its reversal (RentalRecord), and whose bill it is while nothing has been paid on it
     * (a property handover, LC-01; booted() refuses it on any other bill).
     */
    public const MUTABLE = ['paid_minor', 'status', 'voided_at', 'voided_by_id', 'void_reason', 'landlord_id'];

    protected $table = 'rental_charges';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'paid_minor' => 'integer', 'commission_bp' => 'integer', 'commission_minor' => 'integer', 'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'voided_at' => 'datetime'];

    public const KINDS = ['rent', 'item', 'utility', 'deposit', 'late_fee', 'one_off', 'opening', 'refund'];

    /** Which bill a payment settles first, after the due date (KR-10). */
    public const PRIORITY = ['deposit' => 0, 'opening' => 1, 'rent' => 2, 'item' => 3, 'utility' => 4, 'one_off' => 5, 'late_fee' => 6, 'refund' => 7];

    protected static function booted(): void
    {
        static::updating(function (self $bill) {
            if ($bill->isDirty('landlord_id') && ((int) $bill->getOriginal('paid_minor') !== 0 || $bill->getOriginal('status') !== 'open')) {
                throw BusinessRuleException::make('record_locked', 'Money was paid on this bill, or it has closed: it stays with the landlord it was raised for.', ['fields' => ['landlord_id']]);
            }
        });
    }

    public function left(): int
    {
        return max(0, (int) $this->amount_minor - (int) $this->paid_minor);
    }

    public function lease()
    {
        return $this->belongsTo(RentalLease::class, 'lease_id');
    }

    public function allocations()
    {
        return $this->hasMany(RentalAllocation::class, 'charge_id');
    }
}

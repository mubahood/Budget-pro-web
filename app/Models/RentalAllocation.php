<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * How much of a payment settled one bill, and how that part splits: commission, landlord, agency, deposit. A reversal
 * is a negative row that points at the original.
 */
class RentalAllocation extends Model
{
    use RentalRecord;

    /** What a posted row may still change: its caches and its reversal (RentalRecord). */
    public const MUTABLE = ['reversed_at'];

    protected $table = 'rental_allocations';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'commission_minor' => 'integer', 'landlord_minor' => 'integer', 'agency_minor' => 'integer', 'deposit_minor' => 'integer', 'applied_on' => 'date:Y-m-d', 'reversed_at' => 'datetime'];

    public const UPDATED_AT = null;

    public function payment()
    {
        return $this->belongsTo(RentalPayment::class, 'payment_id');
    }

    public function charge()
    {
        return $this->belongsTo(RentalCharge::class, 'charge_id');
    }
}

<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Money paid to a landlord from what was collected for them.
 */
class RentalPayout extends Model
{
    use RentalRecord;

    /** What a posted row may still change: its caches and its reversal (RentalRecord). */
    public const MUTABLE = ['reversed_at', 'reversed_by_id', 'reverse_reason'];

    protected $table = 'rental_payouts';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'paid_on' => 'date:Y-m-d', 'period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'reversed_at' => 'datetime'];

    public function landlord()
    {
        return $this->belongsTo(RentalLandlord::class, 'landlord_id');
    }
}

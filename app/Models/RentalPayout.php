<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Money paid to a landlord from what was collected for them. What it keeps of itself is written once, when it is recorded: where the money
 * went (`paid_to_snapshot`), what was owed before and after, the part paid ahead and why. Rows older than those columns hold null or zero.
 */
class RentalPayout extends Model
{
    use RentalRecord;

    /** What a posted row may still change: its caches and its reversal (RentalRecord). */
    public const MUTABLE = ['reversed_at', 'reversed_by_id', 'reverse_reason'];

    protected $table = 'rental_payouts';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'paid_on' => 'date:Y-m-d', 'period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'reversed_at' => 'datetime', 'balance_before_minor' => 'integer', 'balance_after_minor' => 'integer', 'advance_minor' => 'integer'];

    public function landlord()
    {
        return $this->belongsTo(RentalLandlord::class, 'landlord_id');
    }
}

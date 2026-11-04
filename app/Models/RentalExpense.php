<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Money paid out for a property (charged to its landlord) or for the agency itself.
 */
class RentalExpense extends Model
{
    use RentalRecord;

    /** What a posted row may still change: its caches and its reversal (RentalRecord). */
    public const MUTABLE = ['reversed_at', 'reversed_by_id', 'reverse_reason'];

    protected $table = 'rental_expenses';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'paid_on' => 'date:Y-m-d', 'reversed_at' => 'datetime'];

    public function property()
    {
        return $this->belongsTo(RentalProperty::class, 'property_id');
    }
}

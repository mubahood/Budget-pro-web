<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who owned a property from when (Landlords\Handover, LC-01). A row with `applied_at` empty and not cancelled is a handover waiting for its day.
 *
 * @property Carbon $from_date
 * @property ?Carbon $to_date
 * @property ?Carbon $applied_at
 * @property ?Carbon $cancelled_at
 */
class RentalPropertyOwner extends Model
{
    use RentalRecord;

    protected $table = 'rental_property_owners';

    protected $guarded = ['id'];

    protected $casts = ['from_date' => 'date:Y-m-d', 'to_date' => 'date:Y-m-d', 'applied_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(RentalLandlord::class, 'landlord_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(RentalProperty::class, 'property_id');
    }
}

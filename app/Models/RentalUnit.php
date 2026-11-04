<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A room, flat, house or shop in a property. Occupied is never stored: a unit is occupied while it has a live lease.
 */
class RentalUnit extends Model
{
    use RentalRecord;

    protected $table = 'rental_units';

    protected $guarded = ['id'];

    protected $casts = ['rent_minor' => 'integer', 'deposit_minor' => 'integer', 'cycle_months' => 'integer', 'bedrooms' => 'integer', 'bathrooms' => 'integer', 'size_sqm' => 'integer'];

    public const KINDS = ['single_room', 'double_room', 'self_contained', 'studio', 'apartment', 'house', 'shop', 'office', 'stall', 'warehouse', 'other'];

    /** The live lease (active or under notice), if any. */
    public function liveLease()
    {
        return $this->hasOne(RentalLease::class, 'unit_id')->whereIn('status', RentalLease::LIVE)->latestOfMany();
    }

    public function property()
    {
        return $this->belongsTo(RentalProperty::class, 'property_id');
    }

    public function leases()
    {
        return $this->hasMany(RentalLease::class, 'unit_id');
    }
}

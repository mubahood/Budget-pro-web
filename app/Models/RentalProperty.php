<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A building, estate or plot the account lets out, owned by one landlord.
 */
class RentalProperty extends Model
{
    use RentalRecord;

    protected $table = 'rental_properties';

    protected $guarded = ['id'];

    protected $casts = ['commission_bp' => 'integer', 'commission_minor' => 'integer', 'water_rate_minor' => 'integer', 'power_rate_minor' => 'integer', 'lat' => 'float', 'lng' => 'float'];

    public function landlord()
    {
        return $this->belongsTo(RentalLandlord::class, 'landlord_id');
    }

    public function units()
    {
        return $this->hasMany(RentalUnit::class, 'property_id');
    }

    public function staff()
    {
        return $this->hasMany(RentalPropertyStaff::class, 'property_id');
    }
}

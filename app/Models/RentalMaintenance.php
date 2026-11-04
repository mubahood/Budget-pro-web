<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A repair or problem reported by staff, a tenant or a landlord, followed until it is done (its cost becomes an
 * expense).
 */
class RentalMaintenance extends Model
{
    use RentalRecord;

    protected $table = 'rental_maintenance';

    protected $guarded = ['id'];

    protected $casts = ['photos' => 'array', 'cost_minor' => 'integer', 'resolved_on' => 'date:Y-m-d'];

    public function property()
    {
        return $this->belongsTo(RentalProperty::class, 'property_id');
    }

    public function unit()
    {
        return $this->belongsTo(RentalUnit::class, 'unit_id');
    }
}

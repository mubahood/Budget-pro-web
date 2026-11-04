<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A caretaker assigned to a property: a caretaker sees only their properties (KR-17).
 */
class RentalPropertyStaff extends Model
{
    use RentalRecord;

    protected $table = 'rental_property_staff';

    protected $guarded = ['id'];

    public function property()
    {
        return $this->belongsTo(RentalProperty::class, 'property_id');
    }
}

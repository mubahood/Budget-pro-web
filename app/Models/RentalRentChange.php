<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The rent of a lease from a date: a period is billed at the rent in force on its first day.
 */
class RentalRentChange extends Model
{
    use RentalRecord;

    protected $table = 'rental_rent_changes';

    protected $guarded = ['id'];

    protected $casts = ['rent_minor' => 'integer', 'effective_from' => 'date:Y-m-d'];

    public function lease()
    {
        return $this->belongsTo(RentalLease::class, 'lease_id');
    }
}

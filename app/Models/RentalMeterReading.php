<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A water or power meter reading for a unit; the units used since the last reading are billed to the tenant living
 * there.
 */
class RentalMeterReading extends Model
{
    use RentalRecord;

    protected $table = 'rental_meter_readings';

    protected $guarded = ['id'];

    protected $casts = ['reading' => 'float', 'previous' => 'float', 'used' => 'float', 'rate_minor' => 'integer', 'amount_minor' => 'integer', 'read_on' => 'date:Y-m-d', 'reset' => 'boolean'];

    public function unit()
    {
        return $this->belongsTo(RentalUnit::class, 'unit_id');
    }
}

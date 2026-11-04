<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A fee billed beside the rent every period (garbage, security, water flat, service charge), a month's amount.
 */
class RentalLeaseItem extends Model
{
    use RentalRecord;

    protected $table = 'rental_lease_items';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'commissionable' => 'boolean', 'active' => 'boolean', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];

    public function lease()
    {
        return $this->belongsTo(RentalLease::class, 'lease_id');
    }
}

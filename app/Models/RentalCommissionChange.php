<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A lease's commission from a date (Leases\CommissionChanges, LC-15): a period is billed on the rule in force on its first day. Append only: a later
 * change is a new row, and the first change writes a starting row from the lease's own columns.
 *
 * @property Carbon $effective_from
 */
class RentalCommissionChange extends Model
{
    use RentalRecord;

    /** Nothing about a row of this history ever changes (RentalRecord). */
    public const MUTABLE = [];

    public const UPDATED_AT = null;

    protected $table = 'rental_commission_changes';

    protected $guarded = ['id'];

    protected $casts = ['commission_bp' => 'integer', 'commission_minor' => 'integer', 'effective_from' => 'date:Y-m-d'];

    public function lease()
    {
        return $this->belongsTo(RentalLease::class, 'lease_id');
    }
}

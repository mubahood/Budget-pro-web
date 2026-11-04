<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant in a unit from a date: rent per month, billing cycle, deposit and commission (04 §5.7).
 *
 * @property Carbon $start_date
 * @property ?Carbon $end_date
 * @property ?Carbon $billed_until
 * @property ?Carbon $notice_on
 * @property ?Carbon $move_out_on
 * @property ?Carbon $ended_on
 */
class RentalLease extends Model
{
    use RentalRecord;

    protected $table = 'rental_leases';

    protected $guarded = ['id'];

    protected $casts = ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'billed_until' => 'date:Y-m-d', 'notice_on' => 'date:Y-m-d', 'move_out_on' => 'date:Y-m-d', 'ended_on' => 'date:Y-m-d', 'cycle_months' => 'integer', 'rent_minor' => 'integer', 'deposit_minor' => 'integer', 'commission_bp' => 'integer', 'commission_minor' => 'integer'];

    /** A lease that holds its unit: billed, and the tenant lives there. */
    public const LIVE = ['active', 'notice'];

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function unit()
    {
        return $this->belongsTo(RentalUnit::class, 'unit_id');
    }

    public function property()
    {
        return $this->belongsTo(RentalProperty::class, 'property_id');
    }

    public function landlord()
    {
        return $this->belongsTo(RentalLandlord::class, 'landlord_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(RentalTenant::class, 'tenant_id');
    }

    public function items()
    {
        return $this->hasMany(RentalLeaseItem::class, 'lease_id');
    }

    public function rentChanges()
    {
        return $this->hasMany(RentalRentChange::class, 'lease_id');
    }

    public function charges()
    {
        return $this->hasMany(RentalCharge::class, 'lease_id');
    }

    public function payments()
    {
        return $this->hasMany(RentalPayment::class, 'lease_id');
    }
}

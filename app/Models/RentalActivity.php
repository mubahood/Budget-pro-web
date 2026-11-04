<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The Estate Ledger audit trail: who did what, when (staff, a tenant, a landlord or the system).
 */
class RentalActivity extends Model
{
    use RentalRecord;

    protected $table = 'rental_activities';

    protected $guarded = ['id'];

    protected $casts = ['meta' => 'array'];

    public const UPDATED_AT = null;
}

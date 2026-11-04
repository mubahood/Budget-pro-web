<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A person or company who rents (or rented) a unit. One tenant may hold several leases over time.
 */
class RentalTenant extends Model
{
    use RentalRecord;

    protected $table = 'rental_tenants';

    protected $guarded = ['id'];

    protected $hidden = ['id_number'];

    public function leases()
    {
        return $this->hasMany(RentalLease::class, 'tenant_id');
    }

    public function login()
    {
        return $this->hasOne(RentalLogin::class, 'subject_id')->where('subject_type', 'tenant');
    }
}

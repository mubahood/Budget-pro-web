<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A landlord: owns properties the account manages; is paid the rent collected less commission (cash basis, KR-08). In
 * owner mode the one `is_self` landlord is the account itself.
 */
class RentalLandlord extends Model
{
    use RentalRecord;

    protected $table = 'rental_landlords';

    protected $guarded = ['id'];

    protected $casts = ['is_self' => 'boolean', 'commission_bp' => 'integer', 'commission_minor' => 'integer'];

    protected $hidden = ['id_number'];

    public function properties()
    {
        return $this->hasMany(RentalProperty::class, 'landlord_id');
    }

    public function payouts()
    {
        return $this->hasMany(RentalPayout::class, 'landlord_id');
    }

    public function login()
    {
        return $this->hasOne(RentalLogin::class, 'subject_id')->where('subject_type', 'landlord');
    }
}

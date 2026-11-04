<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * One step of chasing arrears: a call, a WhatsApp or SMS reminder, a visit or a note, with an optional promise date.
 */
class RentalFollowUp extends Model
{
    use RentalRecord;

    protected $table = 'rental_follow_ups';

    protected $guarded = ['id'];

    protected $casts = ['owed_minor' => 'integer', 'promise_on' => 'date:Y-m-d'];

    public const UPDATED_AT = null;

    public function lease()
    {
        return $this->belongsTo(RentalLease::class, 'lease_id');
    }
}

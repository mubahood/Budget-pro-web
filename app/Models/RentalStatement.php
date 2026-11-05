<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A landlord's statement as issued when a month closed (LC-03): `body` is the truth, the money columns are for quick totals. It never
 * changes; what stands on it may change only in who was sent it and whether a newer version replaces it (RentalRecord).
 */
class RentalStatement extends Model
{
    use RentalRecord;

    public const MUTABLE = ['status', 'replaced_by_id', 'prepared_at', 'prepared_via', 'prepared_by_id', 'first_opened_at', 'open_count'];

    protected $table = 'rental_statements';

    protected $guarded = ['id'];

    protected $casts = ['month' => 'date:Y-m-d', 'version' => 'integer', 'opening_minor' => 'integer', 'collected_minor' => 'integer', 'commission_minor' => 'integer',
        'expenses_minor' => 'integer', 'payouts_minor' => 'integer', 'deposits_minor' => 'integer', 'other_minor' => 'integer', 'closing_minor' => 'integer', 'body' => 'array',
        'issued_at' => 'datetime', 'prepared_at' => 'datetime', 'first_opened_at' => 'datetime', 'open_count' => 'integer'];

    public function landlord()
    {
        return $this->belongsTo(RentalLandlord::class, 'landlord_id');
    }
}

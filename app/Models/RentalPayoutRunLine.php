<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One landlord on a payout run: how much, from which account, and what they were owed when the draft was saved (PayoutRuns). */
class RentalPayoutRunLine extends Model
{
    use RentalRecord;

    protected $table = 'rental_payout_run_lines';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'balance_at_draft' => 'integer'];

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(RentalLandlord::class, 'landlord_id');
    }
}

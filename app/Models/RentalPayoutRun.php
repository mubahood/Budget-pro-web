<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Many landlords paid at once (LC-09): a draft to print and take to the bank, then posted once as one payout per line, or cancelled.
 * The money is on the payouts, each reversed on its own (PayoutRuns).
 *
 * @property ?Carbon $paid_on
 * @property ?Carbon $posted_at
 */
class RentalPayoutRun extends Model
{
    use RentalRecord;

    public const STATUSES = ['draft', 'posted', 'cancelled'];

    protected $table = 'rental_payout_runs';

    protected $guarded = ['id'];

    protected $casts = ['paid_on' => 'date:Y-m-d', 'posted_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(RentalPayoutRunLine::class, 'run_id');
    }
}

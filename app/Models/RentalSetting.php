<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * One per Estate Ledger account: its mode, billing, commission, deposit and late-fee defaults, numbering, and the row the
 * account's money writes lock (ADR 0010 §6).
 */
class RentalSetting extends Model
{
    use RentalRecord;

    protected $table = 'rental_settings';

    protected $guarded = ['id'];

    protected $casts = ['bill_days_before' => 'integer', 'grace_days' => 'integer', 'prorate_first' => 'boolean', 'commission_bp' => 'integer', 'commission_minor' => 'integer', 'commission_on_items' => 'boolean', 'deposit_months' => 'integer', 'late_fee_minor' => 'integer', 'late_fee_bp' => 'integer', 'messages' => 'array', 'setup_at' => 'datetime'];

    public const MODES = ['agency', 'owner'];

    public function isOwnerMode(): bool
    {
        return $this->mode === 'owner';
    }
}

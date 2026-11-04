<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of the rentals journal (ADR 0010): signed effects on the module's seven accounts that always balance (cash =
 * landlord + deposit + credit + income − expense + equity). Never edited or deleted.
 */
class RentalEntry extends Model
{
    use RentalRecord;

    /** What a posted row may still change: its caches and its reversal (RentalRecord). */
    public const MUTABLE = ['reversed_at'];

    protected $table = 'rental_entries';

    protected $guarded = ['id'];

    protected $casts = ['cash_minor' => 'integer', 'landlord_minor' => 'integer', 'deposit_minor' => 'integer', 'credit_minor' => 'integer', 'income_minor' => 'integer', 'expense_minor' => 'integer', 'equity_minor' => 'integer', 'occurred_on' => 'date:Y-m-d', 'reversed_at' => 'datetime'];

    public const UPDATED_AT = null;

    public const EFFECTS = ['cash_minor', 'landlord_minor', 'deposit_minor', 'credit_minor', 'income_minor', 'expense_minor', 'equity_minor'];
}

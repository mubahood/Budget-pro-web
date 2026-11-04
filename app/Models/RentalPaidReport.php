<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * "I have paid": what a tenant says they paid (Portal\PaidReports). A claim, not money: nothing in the books moves until
 * a person confirms it, which records a real receipt. A tenant reports; only staff decide.
 */
class RentalPaidReport extends Model
{
    use RentalRecord;

    public const STATUSES = ['pending', 'confirmed', 'declined'];

    protected $table = 'rental_paid_reports';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'paid_on' => 'date:Y-m-d', 'decided_at' => 'datetime'];
}

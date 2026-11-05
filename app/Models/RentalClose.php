<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * One closing of a month (LC-02): its totals and their proof. Reopening marks the row and closing again writes the next version, so what a
 * close said stays readable. It posts nothing and moves no money.
 */
class RentalClose extends Model
{
    use RentalRecord;

    /** What a written close may still change: being reopened (RentalRecord). */
    public const MUTABLE = ['status', 'reopened_at', 'reopened_by_id', 'reopen_reason'];

    protected $table = 'rental_closes';

    protected $guarded = ['id'];

    protected $casts = ['month' => 'date:Y-m-d', 'version' => 'integer', 'closed_at' => 'datetime', 'reopened_at' => 'datetime', 'totals' => 'array'];
}

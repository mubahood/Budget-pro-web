<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A cart parked at a till (SUPERMARKET_PLAN.md A6, StoreFeatures `held_carts`), resumable from any
 * lane of the shop. Not synced to phones; written only through App\Services\Shop\HeldCartService.
 */
class HeldCart extends Model
{
    protected $table = 'held_carts';

    protected $guarded = ['id'];

    protected $casts = ['lines' => 'array', 'total' => 'decimal:2', 'line_count' => 'integer'];
}

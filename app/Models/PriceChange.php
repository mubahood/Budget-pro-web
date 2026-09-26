<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One price change in the price book (SUPERMARKET_PLAN.md B1, StoreFeatures `price_book`): applied
 * (applied_at set) or scheduled for starts_at. Written only through App\Services\Shop\PriceBookService.
 */
class PriceChange extends Model
{
    protected $table = 'price_changes';

    protected $guarded = ['id'];

    protected $casts = [
        'old' => 'decimal:2', 'new' => 'decimal:2', 'starts_at' => 'datetime', 'applied_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];
}

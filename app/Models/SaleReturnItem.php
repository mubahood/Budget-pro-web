<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One returned line. */
class SaleReturnItem extends Model
{
    protected $table = 'sale_return_items';

    protected $fillable = ['company_id', 'sale_return_id', 'sale_record_item_id', 'stock_item_id', 'quantity', 'value', 'restock', 'stock_record_id'];

    protected $casts = ['quantity' => 'decimal:3', 'value' => 'decimal:2', 'restock' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

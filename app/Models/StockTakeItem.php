<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One counted product. */
class StockTakeItem extends Model
{
    protected $table = 'stock_take_items';

    protected $fillable = ['company_id', 'stock_take_id', 'stock_item_id', 'system_quantity', 'counted_quantity', 'delta', 'stock_record_id'];

    protected $casts = ['system_quantity' => 'decimal:3', 'counted_quantity' => 'decimal:3', 'delta' => 'decimal:3'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}

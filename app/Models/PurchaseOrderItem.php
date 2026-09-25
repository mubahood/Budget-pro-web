<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $stock_item_id
 * @property string|float $quantity
 * @property string|float $received_quantity
 * @property string|float $unit_cost
 */
class PurchaseOrderItem extends Model
{
    protected $fillable = ['company_id', 'purchase_order_id', 'stock_item_id', 'quantity', 'received_quantity', 'unit_cost'];

    protected $casts = ['quantity' => 'decimal:3', 'received_quantity' => 'decimal:3', 'unit_cost' => 'decimal:2'];

    /** @return BelongsTo<StockItem, PurchaseOrderItem> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id')->withoutGlobalScopes();
    }

    public function outstanding(): float
    {
        return max(0.0, round((float) $this->quantity - (float) $this->received_quantity, 3));
    }
}

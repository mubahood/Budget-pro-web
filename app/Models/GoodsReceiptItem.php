<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One received line. */
class GoodsReceiptItem extends Model
{
    protected $table = 'goods_receipt_items';

    protected $fillable = ['company_id', 'goods_receipt_id', 'stock_item_id', 'quantity', 'unit_cost', 'stock_record_id', 'purchase_order_item_id', 'expected_unit_cost', 'batch_number', 'expiry_date'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}

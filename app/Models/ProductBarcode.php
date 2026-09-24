<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Extra barcodes per product (unique per company); a carton barcode can carry its unit. */
class ProductBarcode extends Model
{
    use Syncable;

    protected $table = 'product_barcodes';

    protected $fillable = ['uuid', 'company_id', 'stock_item_id', 'barcode', 'unit_id', 'created_by_id'];

    protected $casts = ['is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}

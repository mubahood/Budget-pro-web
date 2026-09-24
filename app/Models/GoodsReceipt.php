<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Goods received into stock (GRN-lite, plan A4/P2-7). */
class GoodsReceipt extends Model
{
    use Syncable;

    protected $table = 'goods_receipts';

    protected $fillable = ['uuid', 'company_id', 'number', 'supplier_id', 'invoice_ref', 'received_on', 'total_cost', 'amount_paid', 'payment_method', 'notes', 'device_id', 'created_by_id'];

    protected $casts = ['received_on' => 'date', 'total_cost' => 'decimal:2', 'amount_paid' => 'decimal:2', 'is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }
}

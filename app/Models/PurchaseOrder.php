<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Purchase order (plan A5, P4-1): draft → sent → partially_received → received,
 * or cancelled. Stock only moves when goods are received (GoodsReceipt).
 *
 * @property int $id
 * @property int $company_id
 * @property string $number
 * @property int|null $supplier_id
 * @property string $status
 * @property \Illuminate\Support\Carbon $order_date
 * @property \Illuminate\Support\Carbon|null $expected_date
 * @property string|float $subtotal
 */
class PurchaseOrder extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'sent', 'partially_received', 'received', 'cancelled'];

    protected $fillable = ['company_id', 'number', 'supplier_id', 'status', 'order_date', 'expected_date', 'subtotal', 'notes', 'created_by_id', 'sent_at', 'received_at', 'cancelled_at'];

    protected $casts = ['order_date' => 'date', 'expected_date' => 'date', 'sent_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime', 'subtotal' => 'decimal:2'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    /** @return HasMany<PurchaseOrderItem> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** @return BelongsTo<Supplier, PurchaseOrder> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withoutGlobalScopes();
    }

    /** @return HasMany<GoodsReceipt> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->withoutGlobalScopes();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'sent', 'partially_received'], true);
    }
}

<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods sent back to a supplier (plan A5, P4-1): stock leaves at cost, the
 * supplier balance drops by the value, any cash refund is recorded as income.
 *
 * @property int $id
 * @property int $company_id
 * @property string $number
 * @property int|null $supplier_id
 * @property string|float $total_value
 * @property string|float $refund_amount
 */
class PurchaseReturn extends Model
{
    protected $fillable = ['company_id', 'number', 'supplier_id', 'goods_receipt_id', 'returned_on', 'total_value', 'refund_amount', 'refund_method', 'reason', 'created_by_id'];

    protected $casts = ['returned_on' => 'date', 'total_value' => 'decimal:2', 'refund_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    /** @return HasMany<PurchaseReturnItem> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }
}

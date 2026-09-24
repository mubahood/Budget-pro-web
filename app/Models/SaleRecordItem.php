<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sale. Prices/costs are snapshotted at sale time; the
 * authoritative maths (discount allocation, profit, stock movement) runs in
 * SaleService::finalize(). The hooks here only keep a line self-consistent.
 */
class SaleRecordItem extends Model
{
    use HasFactory, Syncable;

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'profit' => 'decimal:2',
        'returned_quantity' => 'decimal:3',
        'unit_factor' => 'decimal:3',
    ];

    protected $fillable = [
        'company_id', 'sale_record_id', 'stock_item_id', 'unit_id', 'unit_factor', 'returned_quantity', 'stock_record_id', 'item_name', 'item_sku', 'quantity',
        'unit_price', 'subtotal', 'discount_amount', 'line_total', 'unit_cost', 'profit',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (SaleRecordItem $item) {
            if (empty($item->stock_item_id)) {
                throw BusinessRuleException::make('product_required', 'Stock item is required.');
            }
            if ((float) $item->quantity <= 0) {
                throw BusinessRuleException::make('invalid_quantity', 'Quantity must be greater than zero.');
            }

            $stockItem = StockItem::withoutGlobalScopes()->find($item->stock_item_id);
            if ($stockItem === null) {
                throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
            }
            if (empty($item->company_id)) {
                $sale = $item->sale_record_id ? SaleRecord::withoutGlobalScopes()->find($item->sale_record_id) : null;
                $item->company_id = $sale?->company_id ?? $stockItem->company_id;
            }
            if ((int) $stockItem->company_id !== (int) $item->company_id) {
                throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
            }

            $item->item_name = $item->item_name ?: $stockItem->name;
            $item->item_sku = $item->item_sku ?: ($stockItem->sku ?? '');
            $factor = max(0.001, (float) ($item->unit_factor ?: 1));
            if ($item->getAttribute('unit_cost') === null) {
                $item->unit_cost = round((float) ($stockItem->buying_price ?? 0) * $factor, 2);
            }
            if ($item->getAttribute('unit_price') === null || (float) $item->unit_price <= 0) {
                $item->unit_price = round((float) ($stockItem->selling_price ?? 0) * $factor, 2);
            }
            $item->discount_amount = round((float) ($item->discount_amount ?? 0), 2);
            $item->recompute();
        });

        static::updating(function (SaleRecordItem $item) {
            if ($item->isDirty(['quantity', 'unit_price', 'unit_cost', 'discount_amount'])) {
                $item->recompute();
            }
        });
    }

    public function recompute(): void
    {
        $qty = (float) $this->quantity;
        $this->subtotal = round($qty * (float) $this->unit_price, 2);
        $this->discount_amount = min(round((float) $this->discount_amount, 2), (float) $this->subtotal);
        $this->line_total = round((float) $this->subtotal - (float) $this->discount_amount, 2);
        $this->profit = round((float) $this->line_total - ((float) $this->unit_cost * $qty), 2);
    }

    public function calculateSubtotal()
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }

    public function calculateProfit()
    {
        return round((float) $this->line_total - ((float) $this->unit_cost * (float) $this->quantity), 2);
    }

    public function saleRecord(): BelongsTo
    {
        return $this->belongsTo(SaleRecord::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function stockRecord(): BelongsTo
    {
        return $this->belongsTo(StockRecord::class);
    }
}

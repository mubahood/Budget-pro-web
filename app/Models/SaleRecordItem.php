<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class SaleRecordItem extends Model
{
    use HasFactory;
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'profit' => 'decimal:2',
    ];
    
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'sale_record_id',
        'stock_item_id',
        'stock_record_id',
        'item_name',
        'item_sku',
        'quantity',
        'unit_price',
        'subtotal',
        'unit_cost',
        'profit',
    ];
    
    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();
        
        // Before creating a sale record item
        // Note: Main processing is now handled by SaleRecord->processAndCompute()
        // This event is kept for basic validation only
        static::creating(function ($item) {
            try {
                // Basic validation
                if (empty($item->stock_item_id)) {
                    throw new \Exception('Stock item is required.');
                }
                
                if (empty($item->quantity) || $item->quantity <= 0) {
                    throw new \Exception('Quantity must be greater than zero.');
                }
                
                // Get stock item for basic data
                $stockItem = \App\Models\StockItem::find($item->stock_item_id);
                if (!$stockItem) {
                    throw new \Exception('Stock item not found.');
                }
                
                // Set basic defaults if not already set
                // Full computation will be done by processAndCompute()
                if (empty($item->item_name)) {
                    $item->item_name = $stockItem->name;
                }
                
                if (empty($item->item_sku)) {
                    $item->item_sku = $stockItem->sku ?? '';
                }
                
                if (empty($item->unit_cost)) {
                    $item->unit_cost = $stockItem->buying_price ?? 0;
                }
                
                if (empty($item->unit_price) || $item->unit_price <= 0) {
                    $item->unit_price = $stockItem->selling_price ?? 0;
                }
                
                // Calculate basic subtotal
                $item->subtotal = $item->quantity * $item->unit_price;
                $item->profit = $item->subtotal - ($item->unit_cost * $item->quantity);
                
            } catch (\Exception $e) {
                Log::error('SaleRecordItem creating error: ' . $e->getMessage());
                throw $e;
            }
        });
        
        // Before updating a sale record item
        static::updating(function ($item) {
            try {
                // Recalculate subtotal and profit
                if ($item->isDirty(['quantity', 'unit_price', 'unit_cost'])) {
                    $item->subtotal = $item->quantity * $item->unit_price;
                    $item->profit = $item->subtotal - ($item->unit_cost * $item->quantity);
                }
                
            } catch (\Exception $e) {
                Log::error('SaleRecordItem updating error: ' . $e->getMessage());
                throw $e;
            }
        });
    }
    
    /**
     * Calculate subtotal.
     */
    public function calculateSubtotal()
    {
        return $this->quantity * $this->unit_price;
    }
    
    /**
     * Calculate profit.
     */
    public function calculateProfit()
    {
        return $this->subtotal - ($this->unit_cost * $this->quantity);
    }
    
    /**
     * Relationships
     */
    public function saleRecord()
    {
        return $this->belongsTo(SaleRecord::class);
    }
    
    public function stockItem()
    {
        return $this->belongsTo(\App\Models\StockItem::class);
    }
    
    public function stockRecord()
    {
        return $this->belongsTo(\App\Models\StockRecord::class);
    }
}

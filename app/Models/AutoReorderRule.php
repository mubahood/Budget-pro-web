<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class AutoReorderRule extends Model
{
    use HasFactory, AuditLogger;
    
    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $fillable = [
        'company_id',
        'stock_item_id',
        'is_enabled',
        'rule_name',
        'reorder_point',
        'reorder_quantity',
        'min_stock_level',
        'max_stock_level',
        'preferred_supplier_name',
        'preferred_supplier_email',
        'preferred_supplier_phone',
        'preferred_supplier_address',
        'preferred_unit_price',
        'lead_time_days',
        'use_forecasting',
        'forecast_algorithm',
        'forecast_horizon_days',
        'reorder_method',
        'holding_cost_percentage',
        'ordering_cost',
        'requires_approval',
        'auto_approve_threshold',
        'check_frequency',
        'check_time',
        'check_days',
        'send_email_notification',
        'notification_emails',
        'last_checked_at',
        'last_triggered_at',
        'times_triggered',
        'notes',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'preferred_unit_price' => 'decimal:2',
        'holding_cost_percentage' => 'decimal:2',
        'ordering_cost' => 'decimal:2',
        'auto_approve_threshold' => 'decimal:2',
        'use_forecasting' => 'boolean',
        'requires_approval' => 'boolean',
        'send_email_notification' => 'boolean',
        'check_days' => 'array',
        'notification_emails' => 'array',
        'last_checked_at' => 'datetime',
        'last_triggered_at' => 'datetime',
    ];

    // Relationships
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    // Helper methods
    public function shouldTriggerReorder($currentStock)
    {
        if (!$this->is_enabled) {
            return false;
        }
        
        return $currentStock <= $this->reorder_point;
    }

    public function calculateEOQ()
    {
        // Economic Order Quantity formula: √(2DS/H)
        // D = Annual demand
        // S = Ordering cost
        // H = Holding cost per unit per year
        
        if ($this->ordering_cost <= 0 || $this->holding_cost_percentage <= 0) {
            return $this->reorder_quantity;
        }
        
        // Estimate annual demand (simplified)
        $annualDemand = $this->reorder_quantity * (365 / 30); // Rough estimate
        $holdingCost = $this->preferred_unit_price * ($this->holding_cost_percentage / 100);
        
        $eoq = sqrt((2 * $annualDemand * $this->ordering_cost) / $holdingCost);
        
        return round($eoq);
    }

    public function getReorderQuantity($forecast = null)
    {
        switch ($this->reorder_method) {
            case 'economic_order_quantity':
                return $this->calculateEOQ();
                
            case 'forecast_based':
                if ($forecast && $this->use_forecasting) {
                    // Order enough to cover forecast period plus safety stock
                    $forecastDemand = $forecast->predicted_demand ?? 0;
                    $safetyStock = $forecast->safety_stock ?? 0;
                    return max($forecastDemand + $safetyStock, $this->reorder_quantity);
                }
                return $this->reorder_quantity;
                
            case 'fixed_quantity':
            default:
                return $this->reorder_quantity;
        }
    }

    public function shouldAutoApprove($totalAmount)
    {
        if (!$this->requires_approval) {
            return true;
        }
        
        if ($this->auto_approve_threshold === null) {
            return false;
        }
        
        return $totalAmount <= $this->auto_approve_threshold;
    }

    public function incrementTriggerCount()
    {
        $this->times_triggered++;
        $this->last_triggered_at = now();
        $this->save();
    }

    public function updateLastChecked()
    {
        $this->last_checked_at = now();
        $this->save();
    }
}

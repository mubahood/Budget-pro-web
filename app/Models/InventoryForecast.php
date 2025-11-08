<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class InventoryForecast extends Model
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
        'financial_period_id',
        'forecast_date',
        'forecast_period',
        'historical_average',
        'historical_min',
        'historical_max',
        'standard_deviation',
        'predicted_demand',
        'predicted_min',
        'predicted_max',
        'confidence_level',
        'trend',
        'trend_percentage',
        'is_seasonal',
        'seasonal_factors',
        'recommended_reorder_point',
        'recommended_order_quantity',
        'safety_stock',
        'current_stock',
        'days_until_stockout',
        'stock_status',
        'algorithm_used',
        'algorithm_parameters',
        'forecast_accuracy',
        'notes',
        'action_required',
        'recommended_action',
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'standard_deviation' => 'decimal:2',
        'confidence_level' => 'decimal:2',
        'trend_percentage' => 'decimal:2',
        'is_seasonal' => 'boolean',
        'seasonal_factors' => 'array',
        'algorithm_parameters' => 'array',
        'forecast_accuracy' => 'decimal:2',
        'action_required' => 'boolean',
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

    public function financialPeriod()
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    // Accessors
    public function getStockStatusBadgeAttribute()
    {
        $badges = [
            'overstocked' => '<span class="badge badge-warning">Overstocked</span>',
            'optimal' => '<span class="badge badge-success">Optimal</span>',
            'low' => '<span class="badge badge-warning">Low Stock</span>',
            'critical' => '<span class="badge badge-danger">Critical</span>',
            'stockout' => '<span class="badge badge-dark">Stock Out</span>',
        ];
        
        return $badges[$this->stock_status] ?? '<span class="badge badge-secondary">' . ucfirst($this->stock_status) . '</span>';
    }

    public function getTrendBadgeAttribute()
    {
        $badges = [
            'increasing' => '<span class="badge badge-success"><i class="fa fa-arrow-up"></i> Increasing</span>',
            'stable' => '<span class="badge badge-info"><i class="fa fa-minus"></i> Stable</span>',
            'decreasing' => '<span class="badge badge-danger"><i class="fa fa-arrow-down"></i> Decreasing</span>',
            'seasonal' => '<span class="badge badge-primary"><i class="fa fa-calendar"></i> Seasonal</span>',
            'volatile' => '<span class="badge badge-warning"><i class="fa fa-exclamation"></i> Volatile</span>',
        ];
        
        return $badges[$this->trend] ?? '<span class="badge badge-secondary">' . ucfirst($this->trend) . '</span>';
    }

    // Helper methods
    public function needsReorder()
    {
        return $this->current_stock <= $this->recommended_reorder_point;
    }

    public function isCritical()
    {
        return in_array($this->stock_status, ['critical', 'stockout']);
    }

    public function getConfidenceLevelColor()
    {
        if ($this->confidence_level >= 80) return 'success';
        if ($this->confidence_level >= 60) return 'info';
        if ($this->confidence_level >= 40) return 'warning';
        return 'danger';
    }
}

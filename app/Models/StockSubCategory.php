<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class StockSubCategory extends Model
{
    use HasFactory, AuditLogger;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    /**
     * The relationships that should always be loaded.
     */
    protected $with = ['stockCategory', 'company'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'buying_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'expected_profit' => 'decimal:2',
        'earned_profit' => 'decimal:2',
        'current_quantity' => 'decimal:2',
        'reorder_level' => 'decimal:2',
    ];

    //fillables
    protected $fillable = [
        'company_id',
        'stock_category_id',
        'name',
        'description',
        'status',
        'image',
        'buying_price',
        'selling_price',
        'expected_profit',
        'earned_profit',
        'measurement_unit',
        'current_quantity',
        'reorder_level',
        'in_stock',
    ]; 
    


    //update_self
    public function update_self()
    {
        $active_financial_period = Utils::getActiveFinancialPeriod($this->company_id);
        if ($active_financial_period == null) {
            return;
        }
        $total_buying_price = 0;
        $total_selling_price = 0;
        $current_quantity = 0;

        $stock_items = StockItem::where('stock_sub_category_id', $this->id)
            ->where('financial_period_id', $active_financial_period->id)
            ->get();

        foreach ($stock_items as $key => $value) {
            $total_buying_price += ($value->buying_price * $value->original_quantity);
            $total_selling_price += ($value->selling_price * $value->original_quantity);
            $current_quantity += $value->current_quantity;
        }

        $total_expected_profit = $total_selling_price - $total_buying_price;

        $this->buying_price = $total_buying_price;
        $this->selling_price = $total_selling_price;
        $this->expected_profit = $total_expected_profit;
        $this->current_quantity = $current_quantity;

        //check if in_stock
        if ($current_quantity > $this->reorder_level) {
            $this->in_stock = 'Yes';
        } else {
            $this->in_stock = 'No';
        }

        //earned_profit
        $this->earned_profit = StockRecord::where('stock_sub_category_id', $this->id)
            ->where('financial_period_id', $active_financial_period->id)
            ->sum('profit');

        $this->save();
    }

    public function stockCategory()
    {
        return $this->belongsTo(StockCategory::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function stockItems()
    {
        return $this->hasMany(StockItem::class, 'stock_sub_category_id');
    }

    public function stockRecords()
    {
        return $this->hasMany(StockRecord::class, 'stock_sub_category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Query Scopes
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('stock_category_id', $categoryId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'Active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'Inactive');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeInStock($query)
    {
        return $query->where('in_stock', 'Yes')
                    ->where('current_quantity', '>', 0);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('current_quantity', '<=', 0)
                    ->orWhere('in_stock', 'No');
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('current_quantity', '<', 'reorder_level');
    }

    protected $appends = ['name_text'];

    //getter for name_text
    public function getNameTextAttribute()
    {
        $name_text = $this->name;
        if ($this->stockCategory != null) {
            $name_text =  $name_text . " - " . $this->stockCategory->name;
        }
        return $name_text;
    }
}

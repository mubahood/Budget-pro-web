<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class StockCategory extends Model
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
    protected $with = ['company'];
    
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
    
    protected $fillable = [
        'company_id',
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
    ]; 

    use HasFactory;

    public function update_self()
    {
        $active_financial_period = Utils::getActiveFinancialPeriod($this->company_id);
        if ($active_financial_period == null) {
            return;
        }
        $total_buying_price = 0;
        $total_selling_price = 0;

        $stock_items = StockItem::where('stock_category_id', $this->id)
            ->where('financial_period_id', $active_financial_period->id)
            ->get();
        foreach ($stock_items as $key => $value) {
            $total_buying_price += ($value->buying_price * $value->original_quantity);
            $total_selling_price += ($value->selling_price * $value->original_quantity);
        }

        $total_expected_profit = $total_selling_price - $total_buying_price;


        $this->earned_profit = StockRecord::where('stock_category_id', $this->id)
            ->where('financial_period_id', $active_financial_period->id)
            ->sum('profit');


        $this->buying_price = $total_buying_price;
        $this->selling_price = $total_selling_price;
        $this->expected_profit = $total_expected_profit;
        $this->save();
    }


    protected $appends = ['name_text'];

    //name_text
    public function getNameTextAttribute()
    {
        return $this->name . " (" . $this->code . ")";
    }

    /**
     * Relationships
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function stockSubCategories()
    {
        return $this->hasMany(StockSubCategory::class, 'parent_id');
    }

    public function stockItems()
    {
        return $this->hasMany(StockItem::class, 'stock_category_id');
    }

    public function stockRecords()
    {
        return $this->hasMany(StockRecord::class, 'stock_category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Query Scopes
     */
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

    public function scopeLowStock($query)
    {
        return $query->where('current_quantity', '<', 'reorder_level');
    }

    public function scopeInStock($query)
    {
        return $query->where('current_quantity', '>', 0);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('current_quantity', '<=', 0);
    }

    /* 
        "earned_profit" => 0
*/
}

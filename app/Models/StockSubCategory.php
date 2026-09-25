<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockSubCategory extends Model
{
    use AuditLogger, HasFactory, Syncable;

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

    /**
     * Refresh the cached totals. Stock on hand and value cover every live product in the
     * sub-category, whatever financial period it was created in (a new period must not make
     * the shelf look empty); earned profit is for the active period when there is one.
     */
    public function update_self()
    {
        $totals = \Illuminate\Support\Facades\DB::table('stock_items')
            ->where('company_id', $this->company_id)
            ->where('stock_sub_category_id', $this->id)
            ->where('is_deleted', 0)
            ->selectRaw('COALESCE(SUM(buying_price * original_quantity), 0) AS buying, COALESCE(SUM(selling_price * original_quantity), 0) AS selling, COALESCE(SUM(current_quantity), 0) AS quantity')
            ->first();

        $current_quantity = (float) $totals->quantity;
        $this->buying_price = (float) $totals->buying;
        $this->selling_price = (float) $totals->selling;
        $this->expected_profit = (float) $totals->selling - (float) $totals->buying;
        $this->current_quantity = $current_quantity;
        $this->in_stock = $current_quantity > (float) $this->reorder_level ? 'Yes' : 'No';

        $active_financial_period = Utils::getActiveFinancialPeriod($this->company_id);
        $this->earned_profit = StockRecord::withoutGlobalScopes()
            ->where('company_id', $this->company_id)
            ->where('stock_sub_category_id', $this->id)
            ->when($active_financial_period !== null, fn ($q) => $q->where('financial_period_id', $active_financial_period->id))
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
            $name_text = $name_text.' - '.$this->stockCategory->name;
        }

        return $name_text;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class StockRecord extends Model
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
    protected $with = ['stockItem', 'createdBy'];
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'date' => 'datetime',
        'quantity' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'buying_price' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'profit' => 'decimal:2',
    ];
    
    /*         
            $table->foreignIdFor(Company::class);
            $table->foreignIdFor(StockItem::class);
            $table->foreignIdFor(StockCategory::class);
            $table->foreignIdFor(StockSubCategory::class);
            $table->foreignIdFor(User::class, 'created_by_id');
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->string('measurement_unit');
            $table->string('description')->nullable();
            $table->string('type');
            $table->float('quantity');
            $table->float('selling_price');
            $table->float('total_sales'); */
    //fillables for above
    protected $fillable = [
        'company_id',
        'stock_item_id',
        'stock_category_id',
        'stock_sub_category_id',
        'financial_period_id',
        'created_by_id',
        'sku',
        'name',
        'measurement_unit',
        'description',
        'type',
        'quantity',
        'selling_price',
        'buying_price',
        'total_sales',
        'profit',
        'date',
    ];


    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            $stock_item = StockItem::find($model->stock_item_id);
            if ($stock_item == null) {
                throw new \Exception("Invalid Stock Item.");
            }

            $financial_period = Utils::getActiveFinancialPeriod($stock_item->company_id);

            if ($financial_period == null) {
                throw new \Exception("Invalid Financial Period");
            }
            $model->financial_period_id = $financial_period->id;


            $model->company_id = $stock_item->company_id;
            $model->stock_category_id = $stock_item->stock_category_id;
            $model->stock_sub_category_id = $stock_item->stock_sub_category_id;
            $model->sku = $stock_item->sku;
            $model->name = $stock_item->name;
            $model->measurement_unit = $stock_item->stockSubCategory->measurement_unit;
            if ($model->description == null) {
                $model->description = $stock_item->type;
            }
            $quantity = abs($model->quantity);
            if ($quantity < 1) {
                throw new \Exception("Invalid Quantity.");
            }
            $model->selling_price = $stock_item->selling_price;
            $model->total_sales = $model->selling_price * $quantity;
            $model->quantity = $quantity;

            if (
                $model->type == 'Sale'
            ) {
                $model->total_sales = abs($model->total_sales);
                $model->profit = $model->total_sales - ($stock_item->buying_price * $quantity);
            } else {
                $model->total_sales = 0;
                $model->profit = 0;
            }

            // Validate sufficient stock BEFORE attempting save (but DON'T update quantity yet)
            $current_quantity = $stock_item->current_quantity;
            if ($current_quantity < $quantity) {
                throw new \Exception("Insufficient Stock. Available: {$current_quantity}, Requested: {$quantity}");
            }

            // DON'T update stock quantities here - that happens in 'created' event
            // This prevents transaction rollback issues

            return $model;
        });

        //created 
        static::created(function ($model) {
            return DB::transaction(function () use ($model) {
                $stock_item = StockItem::find($model->stock_item_id);
                if ($stock_item == null) {
                    throw new \Exception("Invalid Stock Item.");
                }

                // UPDATE STOCK QUANTITIES - This runs AFTER the record is successfully saved
                $quantity = abs($model->quantity);
                
                if ($model->type == 'Sale') {
                    // Stock Out (removing inventory)
                    $new_quantity = $stock_item->current_quantity - $quantity;
                    $stock_item->current_quantity = $new_quantity;
                    $stock_item->save();
                    
                    Log::info("Stock Out (Sale): Removed {$quantity} units from item #{$stock_item->id}. New quantity: {$new_quantity}");
                } else {
                    // For other types, log but don't modify quantity (can be extended later)
                    Log::info("Stock Record Type '{$model->type}': No quantity adjustment for item #{$stock_item->id}");
                }

                // Update aggregates
                $stock_item->stockSubCategory->update_self();
                $stock_item->stockSubCategory->stockCategory->update_self();

                // Create financial record for sales
                $company = Company::find($model->company_id);
                if ($company == null) {
                    throw new \Exception("Invalid Company.");
                }

                if ($model->type == 'Sale') {
                    $financial_category = FinancialCategory::where([
                        ['company_id', '=', $company->id],
                        ['name', '=', 'Sales']
                    ])->first();
                    if ($financial_category == null) {
                        Company::prepare_account_categories($company->id);
                        $financial_category = FinancialCategory::where([
                            ['company_id', '=', $company->id],
                            ['name', '=', 'Sales']
                        ])->first();
                        if ($financial_category == null) {
                            throw new \Exception("Sales Account Category not found.");
                        }
                    }
                    $fin_rec = new FinancialRecord();
                    $fin_rec->financial_category_id = $financial_category->id;
                    $fin_rec->company_id = $company->id;
                    $fin_rec->user_id = $model->created_by_id;
                    $fin_rec->created_by_id = $model->created_by_id;
                    $fin_rec->amount = $model->total_sales;
                    $fin_rec->quantity = $model->quantity;
                    $fin_rec->type = 'Income';
                    $fin_rec->payment_method = 'Cash';
                    $fin_rec->recipient = '';
                    $fin_rec->receipt = '';
                    $fin_rec->date = $model->date;
                    $fin_rec->description = 'Sales of #' . $model->id;
                    $fin_rec->financial_period_id = $model->financial_period_id;
                    $fin_rec->save();
                }
            });
        });

        // Deleting - restore stock quantities when record is deleted
        static::deleting(function ($model) {
            return DB::transaction(function () use ($model) {
                $stock_item = StockItem::find($model->stock_item_id);
                if ($stock_item == null) {
                    Log::warning("StockRecord #{$model->id} deletion: Stock item not found.");
                    return true;
                }

                // Restore stock quantities
                $quantity = abs($model->quantity);
                
                if ($model->type == 'Sale') {
                    // Restore stock that was removed
                    $new_quantity = $stock_item->current_quantity + $quantity;
                    $stock_item->current_quantity = $new_quantity;
                    $stock_item->save();
                    
                    Log::info("Stock Record Deleted: Restored {$quantity} units to item #{$stock_item->id}. New quantity: {$new_quantity}");
                }

                return true;
            });
        });

        // Deleted - update aggregates after deletion
        static::deleted(function ($model) {
            $stock_item = StockItem::find($model->stock_item_id);
            if ($stock_item != null) {
                $stock_item->stockSubCategory->update_self();
                $stock_item->stockSubCategory->stockCategory->update_self();
            }
        });
    }

    /**
     * Relationships
     */
    public function stockItem()
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function stockCategory()
    {
        return $this->belongsTo(StockCategory::class, 'stock_category_id');
    }

    public function stockSubCategory()
    {
        return $this->belongsTo(StockSubCategory::class, 'stock_sub_category_id');
    }

    public function financialPeriod()
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function saleRecord()
    {
        return $this->belongsTo(SaleRecord::class, 'sale_record_id');
    }

    /**
     * Query Scopes
     */
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeStockIn($query)
    {
        return $query->where('type', 'Stock In');
    }

    public function scopeStockOut($query)
    {
        return $query->whereIn('type', ['Sale', 'Stock Out']);
    }

    public function scopeSales($query)
    {
        return $query->where('type', 'Sale');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeByPeriod($query, $periodId)
    {
        return $query->where('financial_period_id', $periodId);
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('stock_category_id', $categoryId);
    }

    public function scopeBySubCategory($query, $subCategoryId)
    {
        return $query->where('stock_sub_category_id', $subCategoryId);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('date', now()->month)
                    ->whereYear('date', now()->year);
    }

    public function scopeThisYear($query)
    {
        return $query->whereYear('date', now()->year);
    }

    /* 
    									


    */
}

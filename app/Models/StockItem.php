<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class StockItem extends Model
{
    use HasFactory, AuditLogger;

    /**
     * Flag to allow quantity updates from sale processing
     * This is NOT a database column, just a runtime flag
     */
    public $skipQuantityCheck = false;

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
    protected $with = ['stockSubCategory', 'stockCategory'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'buying_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'original_quantity' => 'decimal:2',
        'current_quantity' => 'decimal:2',
    ];

    //fillables
    protected $fillable = [
        'company_id',
        'created_by_id',
        'stock_category_id',
        'stock_sub_category_id',
        'financial_period_id',
        'name',
        'description',
        'image',
        'barcode',
        'sku',
        'generate_sku',
        'update_sku',
        'gallery',
        'buying_price',
        'selling_price',
        'original_quantity',
        'current_quantity',
    ];

    /**
     * Fields that cannot be changed after creation
     */
    protected $immutableFields = [
        'stock_category_id',
        'stock_sub_category_id',
        'financial_period_id',
        'original_quantity',
        'company_id',
        'created_by_id',
    ];
    //boot
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Prepare and validate model
            $model = self::prepare($model);
            
            // Set initial current quantity equal to original quantity
            $model->current_quantity = $model->original_quantity;
            
            // Validate stock quantities
            if ($model->original_quantity < 0) {
                throw new \Exception("Initial stock quantity cannot be negative");
            }
            
            return $model;
        });

        static::updating(function ($model) {
            // Get original values before any changes
            $original = $model->getOriginal();
            
            // Protect immutable fields
            $immutableFields = [
                'stock_category_id' => 'Stock Category',
                'stock_sub_category_id' => 'Stock Sub-Category',
                'financial_period_id' => 'Financial Period',
                'original_quantity' => 'Original Quantity',
                'company_id' => 'Company',
                'created_by_id' => 'Creator',
            ];
            
            foreach ($immutableFields as $field => $label) {
                if (isset($original[$field]) && $model->{$field} != $original[$field]) {
                    throw new \Exception("{$label} cannot be changed after creation. Please create a new stock item instead.");
                }
            }
            
            // Prevent manual changes to current_quantity (should only change via StockRecords)
            if (isset($original['current_quantity']) && $model->current_quantity != $original['current_quantity']) {
                // Check if this is coming from a stock record update (allowed)
                if (!$model->skipQuantityCheck) {
                    throw new \Exception("Current quantity cannot be changed manually. Please use Stock Records to adjust inventory.");
                }
            }
            
            // Prepare other fields
            $model = self::prepare($model, true);
            
            return $model;
        });

        static::created(function ($model) {
            // Update parent categories
            self::updateParentCategories($model);
        });

        static::updated(function ($model) {
            // Update parent categories
            self::updateParentCategories($model);
        });

        static::deleted(function ($model) {
            // Update parent categories
            self::updateParentCategories($model);
        });
    }

    /**
     * Prepare model data before saving
     */
    static public function prepare($model, $isUpdating = false)
    {
        // Validate and set stock sub-category
        $sub_category = StockSubCategory::find($model->stock_sub_category_id);
        if ($sub_category == null) {
            throw new \Exception("Invalid Stock Sub Category. Please select a valid category.");
        }
        
        // Auto-set parent category from sub-category (only on creation)
        if (!$isUpdating) {
            $model->stock_category_id = $sub_category->stock_category_id;
        }

        // Validate user
        $user = User::find($model->created_by_id);
        if ($user == null) {
            throw new \Exception("Invalid User. Authentication error.");
        }
        
        // Get and validate financial period (only on creation)
        if (!$isUpdating) {
            $financial_period = Utils::getActiveFinancialPeriod($user->company_id);
            if ($financial_period == null) {
                throw new \Exception("No active Financial Period found. Please create or activate a financial period first.");
            }
            $model->financial_period_id = $financial_period->id;
            $model->company_id = $user->company_id;
        }

        // Handle SKU generation/update
        if (!$isUpdating) {
            // Creating new item
            if ($model->generate_sku == 'Auto' || empty($model->sku)) {
                $model->sku = self::generateUniqueSKU($model->stock_sub_category_id);
            } else {
                // Manual SKU - validate uniqueness
                self::validateSKUUniqueness($model->sku, $model->company_id);
            }
        } else {
            // Updating existing item
            if ($model->update_sku == "Yes") {
                if ($model->generate_sku == 'Manual' && !empty($model->sku)) {
                    // Validate manual SKU
                    self::validateSKUUniqueness($model->sku, $model->company_id, $model->id);
                } else {
                    // Auto-generate new SKU
                    $model->sku = self::generateUniqueSKU($model->stock_sub_category_id);
                }
            }
        }

        // Validate pricing
        if ($model->buying_price < 0) {
            throw new \Exception("Buying price cannot be negative");
        }
        if ($model->selling_price < 0) {
            throw new \Exception("Selling price cannot be negative");
        }

        return $model;
    }

    /**
     * Generate a unique SKU for the stock item
     */
    static private function generateUniqueSKU($subCategoryId)
    {
        $maxAttempts = 10;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            $sku = Utils::generateSKU($subCategoryId);
            
            // Check if SKU already exists
            $exists = self::where('sku', $sku)->exists();
            if (!$exists) {
                return $sku;
            }
            
            $attempt++;
        }
        
        // Fallback: append timestamp
        return Utils::generateSKU($subCategoryId) . '-' . time();
    }

    /**
     * Validate SKU uniqueness
     */
    static private function validateSKUUniqueness($sku, $companyId, $excludeId = null)
    {
        $query = self::where('sku', $sku)->where('company_id', $companyId);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        
        if ($query->exists()) {
            throw new \Exception("SKU '{$sku}' already exists. Please use a different SKU.");
        }
    }

    /**
     * Update parent categories after stock item changes
     */
    static private function updateParentCategories($model)
    {
        try {
            $stock_category = StockCategory::find($model->stock_category_id);
            if ($stock_category) {
                $stock_category->update_self();
            }

            $stock_sub_category = StockSubCategory::find($model->stock_sub_category_id);
            if ($stock_sub_category) {
                $stock_sub_category->update_self();
            }
        } catch (\Exception $e) {
            // Log error but don't fail the operation
            \Log::error("Failed to update parent categories: " . $e->getMessage());
        }
    }


    //getter for gallery 
    public function getGalleryAttribute($value)
    {
        if ($value != null && strlen($value) > 3) {
            $d = json_decode($value); 
            if (is_array($d)) {
                return $d;
            }
        }
        return [];
    }

    //setter for gallery
    public function setGalleryAttribute($value)
    {
        $this->attributes['gallery'] = json_encode($value, true);
    }

    //appengs for name_text
    protected $appends = ['name_text'];

    //getter for name_text
    public function getNameTextAttribute()
    {
        $name_text = $this->name;
        if ($this->stockSubCategory != null) {
            $name_text =  $name_text . " - " . $this->stockSubCategory->name;
        }
        //add current quantity on name
        $name_text = $name_text . " (" . number_format($this->current_quantity) . " " . $this->stockSubCategory->measurement_unit . ")";
        return $name_text;
    }

    //stockSubCategory relation
    public function stockSubCategory()
    {
        return $this->belongsTo(StockSubCategory::class);
    }

    //stockCategory relation
    public function stockCategory()
    {
        return $this->belongsTo(StockCategory::class);
    }

    //financialPeriod relation
    public function financialPeriod()
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    //createdBy relation
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    //stockRecords relation
    public function stockRecords()
    {
        return $this->hasMany(StockRecord::class);
    }

    /**
     * Scope to get items with low stock (less than 10 units)
     */
    public function scopeLowStock($query)
    {
        return $query->where('current_quantity', '<', 10);
    }

    /**
     * Scope to get items by category
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('stock_category_id', $categoryId);
    }

    /**
     * Scope to get items by sub-category
     */
    public function scopeBySubCategory($query, $subCategoryId)
    {
        return $query->where('stock_sub_category_id', $subCategoryId);
    }

    /**
     * Scope to get items in stock (quantity > 0)
     */
    public function scopeInStock($query)
    {
        return $query->where('current_quantity', '>', 0);
    }

    /**
     * Scope to get out of stock items
     */
    public function scopeOutOfStock($query)
    {
        return $query->where('current_quantity', '<=', 0);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;
use App\Jobs\UpdateFinancialCategoryAggregates;

class FinancialRecord extends Model
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
    protected $with = ['financial_category', 'createdBy'];
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'date' => 'datetime',
        'amount' => 'decimal:2',
        'quantity' => 'decimal:2',
    ];
    
    protected $fillable = [
        'financial_category_id',
        'company_id',
        'user_id',
        'amount',
        'quantity',
        'type',
        'payment_method',
        'recipient',
        'description',
        'receipt',
        'date',
        'financial_period_id',
        'created_by_id',
    ];

    //boot
    protected static function boot()
    {
        parent::boot();

        //creating
        static::creating(function ($model) {
            $user = User::find($model->created_by_id);
            if ($user == null) {
                throw new \Exception("Invalid User");
            }
            $financial_period = Utils::getActiveFinancialPeriod($user->company_id);

            if ($financial_period == null) {
                throw new \Exception("Financial Period is not active. Please activate the financial period.");
            }
            $model->financial_period_id = $financial_period->id;
            $model->company_id = $user->company_id;
        });

        // Created - log after successful creation
        static::created(function ($model) {
            Log::info("Financial Record Created: #{$model->id}, Type: {$model->type}, Amount: {$model->amount}");
            
            // Dispatch job to update financial category totals (async)
            if ($model->financial_category_id) {
                UpdateFinancialCategoryAggregates::dispatch($model->financial_category_id);
            }
        });

        // Updating - validate before updates
        static::updating(function ($model) {
            // Validate financial period is still active
            $financial_period = FinancialPeriod::find($model->financial_period_id);
            if ($financial_period == null || $financial_period->status != 'Active') {
                throw new \Exception("Cannot update record. Financial Period is not active.");
            }

            // Validate amount
            if ($model->amount <= 0) {
                throw new \Exception("Invalid amount. Must be greater than 0.");
            }

            return true;
        });

        // Updated - log changes and update aggregates
        static::updated(function ($model) {
            Log::info("Financial Record Updated: #{$model->id}");
            
            // Dispatch job to update financial category totals (async)
            if ($model->financial_category_id) {
                UpdateFinancialCategoryAggregates::dispatch($model->financial_category_id);
            }
        });

        static::deleting(function ($model) {
            $model->financial_category()->dissociate();
            $model->save();
        });

        // Deleted - update aggregates after deletion
        static::deleted(function ($model) {
            Log::info("Financial Record Deleted: #{$model->id}");
            
            // Dispatch job to update financial category totals (async)
            if ($model->financial_category_id) {
                UpdateFinancialCategoryAggregates::dispatch($model->financial_category_id);
            }
        });
    }

    //appends
    protected $appends = ['financial_category_text'];


    //getter for financial_category_text
    public function getFinancialCategoryTextAttribute()
    {
        if ($this->financial_category) {
            return $this->financial_category->name;
        }
        return 'N/A';
    }

    //belongs financial_category
    public function financial_category()
    {
        return $this->belongsTo(FinancialCategory::class);
    }

    public function financialPeriod()
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Query Scopes
     */
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeIncome($query)
    {
        return $query->where('type', 'Income');
    }

    public function scopeExpense($query)
    {
        return $query->where('type', 'Expense');
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
        return $query->where('financial_category_id', $categoryId);
    }

    public function scopeByPaymentMethod($query, $method)
    {
        return $query->where('payment_method', $method);
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

    public function scopeToday($query)
    {
        return $query->whereDate('date', now()->toDateString());
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class BudgetProgram extends Model
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
        'budget' => 'decimal:2',
        'spent' => 'decimal:2',
        'balance' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    //boot
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            //stop with same name and company_id is the same
            if (BudgetProgram::where('name', $model->name)->where('company_id', $model->company_id)->exists()) {
                throw new \Exception('Name already exists');
            }
            $model = self::prepare($model);
            return $model;
        });

        static::updating(function ($model) {
            //stop with same name but not the same id and company_id is the same
            if (BudgetProgram::where('name', $model->name)->where('id', '!=', $model->id)->where('company_id', $model->company_id)->exists()) {
                throw new \Exception('Name already exists');
            }
            $model = self::prepare($model);
            return $model;
        });
    }

    //public static function prepare
    public static function prepare($data)
    {
        $loggedUser = auth()->user();
        if ($loggedUser == null) {
            throw new \Exception('User not found');
        }
        $data->company_id = $loggedUser->company_id;
        return $data;
    }

    //belongs to company
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    //get categories
    public function categories()
    {
        return $this->hasMany(BudgetItemCategory::class);
    }
    public function get_categories()
    {
        $cats = BudgetItemCategory::where('budget_program_id', $this->id)
            ->orderBy('target_amount', 'desc')
            ->get();
        return $cats;
    }

    //getter for budget_spent
    public function getBudgetSpentAttribute($budget_spent)
    {
        $cats = BudgetItemCategory::where('budget_program_id', $this->id)
            ->get();
        $total = 0;
        foreach ($cats as $cat) {
            $total += $cat->invested_amount;
        }
        return $total;
    }

    //getter for budget_total
    public function getBudgetTotalAttribute($budget_total)
    {
        $cats = BudgetItemCategory::where('budget_program_id', $this->id)
            ->get();
        $total = 0;
        foreach ($cats as $cat) {
            $total += $cat->target_amount;
        }
        return $total;
    }

    //getter for budget_balance
    public function getBudgetBalanceAttribute($budget_balance)
    {
        $cats = BudgetItemCategory::where('budget_program_id', $this->id)
            ->get();
        $total = 0;
        foreach ($cats as $cat) {
            $total += $cat->balance;
        }
        return $total;
    }
    /* 


total_expected
total_in_pledge
budget_total

	
*/
    //update self
    public static function update_self()
    {
    }

    //GETTER FOR title
    public function getTitleAttribute($title)
    {
        if ($title == null || $title == '') {
            return $this->name;
        }
        return $title;
    }

    /**
     * Additional Relationships
     */
    public function budgetItems()
    {
        return $this->hasMany(BudgetItem::class, 'budget_program_id');
    }

    public function contributionRecords()
    {
        return $this->hasMany(ContributionRecord::class, 'budget_program_id');
    }

    public function financialPeriod()
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Query Scopes
     */
    public function scopeByPeriod($query, $periodId)
    {
        return $query->where('financial_period_id', $periodId);
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

    public function scopeThisYear($query)
    {
        return $query->whereYear('created_at', now()->year);
    }
}

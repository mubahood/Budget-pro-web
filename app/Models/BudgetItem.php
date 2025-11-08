<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;
use App\Jobs\SendBudgetItemNotification;

class BudgetItem extends Model
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
    protected $with = ['budgetItemCategory', 'createdBy'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'amount' => 'decimal:2',
        'paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'date' => 'date',
    ];

    //boot
    protected static function boot()
    {
        parent::boot();

        //disable deleting
        static::deleting(function ($model) {
            //throw new \Exception('Deleting is not allowed');
        });

        static::creating(function ($model) {

            $model->name = trim($model->name);
            $withSameName  = BudgetItem::where([
                'name' => $model->name,
                'budget_item_category_id' => $model->budget_item_category_id,
            ])->first();

            if ($withSameName) {
                throw new \Exception('Name already exists');
            }


            $model = self::prepare($model);
            return $model;
        });

        static::updating(function ($model) {
            $model->name = trim($model->name);
            $withSameName  = BudgetItem::where([
                'name' => $model->name,
                'budget_item_category_id' => $model->budget_item_category_id,
            ])->where('id', '!=', $model->id)->first();
            if ($withSameName) {
                throw new \Exception('Name already exists');
            }

            $model = self::prepare($model);
            return $model;
        });

        //updated
        static::updated(function ($model) {
            self::finalizer($model);
        });

        //created
        static::created(function ($model) {
            self::finalizer($model);
        });
    }

    //public static function prepare
    public static function prepare($data)
    {
        $data->target_amount = $data->unit_price * $data->quantity;
        $loggedUser = User::find($data->created_by_id);
        if ($loggedUser == null) {
            throw new \Exception('User not found');
        }
        $data->company_id = $loggedUser->company_id;
        $data->changed_by_id = $loggedUser->id;
        $cat = BudgetItemCategory::find($data->budget_item_category_id);
        if ($cat == null) {
            throw new \Exception('Category not found');
        }
        return $data;
    }

    //public static function finalizer
    public static function finalizer($data)
    {
        $balance = $data->target_amount - $data->invested_amount;
        $is_complete = 'No';
        $percentage_done = 0;

        if ($data->target_amount == 0) {
            $percentage_done = 0;
        } else {
            $percentage_done = ($data->invested_amount / $data->target_amount) * 100;
        }

        if ($percentage_done >= 98) {
            $is_complete = 'Yes';
        } else {
            $is_complete = 'No';
        }
        $table = (new self())->getTable();
        $sql = "UPDATE $table SET 
        balance = $balance, 
        percentage_done = $percentage_done, 
        is_complete = '$is_complete' WHERE id = $data->id";
        DB::update($sql);
        $cat = BudgetItemCategory::find($data->budget_item_category_id);
        
        try {
            $cat->updateSelf();
        } catch (\Throwable $th) {
            //throw $th;
        }

        // Dispatch email notification job (async)
        try {
            SendBudgetItemNotification::dispatch(
                $data->id,
                $data->company_id,
                $data->budget_program_id
            );
        } catch (\Throwable $th) {
            Log::error("Failed to dispatch budget item notification: " . $th->getMessage());
        }
    }

    public function category()
    {
        return $this->belongsTo(BudgetItemCategory::class, 'budget_item_category_id');
    }

    public function budgetItemCategory()
    {
        return $this->belongsTo(BudgetItemCategory::class, 'budget_item_category_id');
    }

    public function budgetProgram()
    {
        return $this->belongsTo(BudgetProgram::class, 'budget_program_id');
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

    /**
     * Query Scopes
     */
    public function scopeByProgram($query, $programId)
    {
        return $query->where('budget_program_id', $programId);
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('budget_item_category_id', $categoryId);
    }

    public function scopeByPeriod($query, $periodId)
    {
        return $query->where('financial_period_id', $periodId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'Pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'Approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'Rejected');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOverBudget($query)
    {
        return $query->whereColumn('spent_amount', '>', 'target_amount');
    }

    public function scopeUnderBudget($query)
    {
        return $query->whereColumn('spent_amount', '<', 'target_amount');
    }

    //getter for budget_item_category_text
    public function getBudgetItemCategoryTextAttribute()
    {
        if ($this->category == null) {
            return 'N/A';
        }
        return $this->category->name;
    }

    //appends budget_item_category_text
    protected $appends = ['budget_item_category_text'];
}

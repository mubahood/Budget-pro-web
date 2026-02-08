<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BudgetItemCategory extends Model
{
    use AuditLogger, HasFactory;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'budget_program_id',
        'company_id',
        'name',
        'target_amount',
        'invested_amount',
        'balance',
        'percentage_done',
        'is_complete',
    ];

    //boot
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->name = trim($model->name);
            // Prevent duplicate names within the same program
            $existing = BudgetItemCategory::where([
                'name' => $model->name,
                'budget_program_id' => $model->budget_program_id,
            ])->first();
            if ($existing) {
                throw new \Exception('Category name already exists in this program');
            }

            $loggedUser = auth()->user();
            if ($loggedUser == null) {
                throw new \Exception('User not found');
            }
            $model->company_id = $loggedUser->company_id;

            // Default calculated fields for new categories (no children yet)
            $model->target_amount = $model->target_amount ?? 0;
            $model->invested_amount = $model->invested_amount ?? 0;
            $model->balance = $model->balance ?? 0;
            $model->percentage_done = $model->percentage_done ?? 0;
            $model->is_complete = $model->is_complete ?? 'No';

            return $model;
        });

        static::updating(function ($model) {
            $model->name = trim($model->name);
            // Prevent duplicate names within the same program (excluding self)
            $existing = BudgetItemCategory::where([
                'name' => $model->name,
                'budget_program_id' => $model->budget_program_id,
            ])->where('id', '!=', $model->id)->first();
            if ($existing) {
                throw new \Exception('Category name already exists in this program');
            }

            return $model;
        });
    }

    //update self
    public function updateSelf()
    {
        $target_amount = BudgetItem::where('budget_item_category_id', $this->id)->sum('target_amount');
        $invested_amount = BudgetItem::where('budget_item_category_id', $this->id)->sum('invested_amount');
        $balance = $target_amount - $invested_amount;
        $percentage_done = 0;
        if ($target_amount > 0) {
            $percentage_done = round(($invested_amount / $target_amount) * 100, 2);
        }

        $is_complete = ($percentage_done >= 98 || $balance <= 0) ? 'Yes' : 'No';

        DB::table((new self())->getTable())
            ->where('id', $this->id)
            ->update([
                'target_amount'   => $target_amount,
                'invested_amount' => $invested_amount,
                'balance'         => $balance,
                'percentage_done' => $percentage_done,
                'is_complete'     => $is_complete,
            ]);

        // Cascade up to parent BudgetProgram
        try {
            if ($this->budget_program_id) {
                BudgetProgram::recalculateFromChildren($this->budget_program_id);
            }
        } catch (\Throwable $th) {
            Log::error('BudgetItemCategory::updateSelf cascade to program failed: ' . $th->getMessage());
        }
    }

    //getter for percentage_done
    public function getPercentageDoneAttribute($percentage_done)
    {
        if ($percentage_done > 100) {
            return 100;
        }
        if ($percentage_done !== null && $percentage_done > 0) {
            return round((float) $percentage_done, 2);
        }
        if ($this->target_amount == 0) {
            return 0;
        }

        return round(($this->invested_amount / $this->target_amount) * 100, 2);
    }

    public function get_items()
    {
        $cats = BudgetItem::where('budget_item_category_id', $this->id)
            ->orderBy('target_amount', 'desc')
            ->get();

        return $cats;
    }

    //getter for name_text
    public function getNameTextAttribute($name_text)
    {
        return $this->name.' ('.number_format($this->balance).')';
    }

    /**
     * Relationships
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function budgetProgram()
    {
        return $this->belongsTo(BudgetProgram::class, 'budget_program_id');
    }

    public function budgetItems()
    {
        return $this->hasMany(BudgetItem::class, 'budget_item_category_id');
    }
}

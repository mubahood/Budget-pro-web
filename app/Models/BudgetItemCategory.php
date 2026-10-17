<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Support\Decimal;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BudgetItemCategory extends Model
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
                throw new BusinessRuleException('Category name already exists in this program');
            }

            // Try Laravel session auth (web portal), then admin guard
            $loggedUser = auth()->user() ?? auth('admin')->user();
            if ($loggedUser !== null) {
                $model->company_id = $loggedUser->company_id;
            }
            // If no auth user (mobile API), company_id must already be set on the model
            if (empty($model->company_id)) {
                throw new BusinessRuleException('User not found — unable to determine company.');
            }

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
                throw new BusinessRuleException('Category name already exists in this program');
            }

            return $model;
        });
    }

    //update self
    public function updateSelf()
    {
        // Exact sums (bcmath on the DECIMAL strings), straight from the table so the scope of the caller does not matter.
        $sums = DB::table('budget_items')->where('budget_item_category_id', $this->id)->where('is_deleted', 0)
            ->selectRaw('COALESCE(SUM(target_amount), 0) AS t, COALESCE(SUM(invested_amount), 0) AS i')->first();
        $target_amount = Decimal::of((string) ($sums->t ?? 0));
        $invested_amount = Decimal::of((string) ($sums->i ?? 0));
        $balance = Decimal::sub($target_amount, $invested_amount);
        $percentage_done = Decimal::percent($invested_amount, $target_amount);

        $is_complete = (Decimal::cmp($percentage_done, '98') >= 0 || Decimal::cmp($balance, '0') <= 0) ? 'Yes' : 'No';

        DB::table((new self())->getTable())
            ->where('id', $this->id)
            ->update([
                'target_amount' => $target_amount,
                'invested_amount' => $invested_amount,
                'balance' => $balance,
                'percentage_done' => $percentage_done,
                'is_complete' => $is_complete,
            ]);

        // Cascade up to parent BudgetProgram
        try {
            if ($this->budget_program_id) {
                BudgetProgram::recalculateFromChildren($this->budget_program_id);
            }
        } catch (\Throwable $th) {
            Log::error('BudgetItemCategory::updateSelf cascade to program failed: '.$th->getMessage());
        }
    }

    //getter for percentage_done
    public function getPercentageDoneAttribute($percentage_done)
    {
        // Was clamped to 100 here, which hid genuinely over-budget categories
        // (invested_amount > target_amount is a real, useful signal for a
        // budget tracker to surface, not something to mask as "100% done").
        if (Decimal::valid($percentage_done) && Decimal::cmp((string) $percentage_done, '0') > 0) {
            return Decimal::of((string) $percentage_done);
        }

        return Decimal::percent(Decimal::valid($this->invested_amount) ? (string) $this->invested_amount : '0', Decimal::valid($this->target_amount) ? (string) $this->target_amount : '0');
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

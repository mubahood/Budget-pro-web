<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Services\Budget\BudgetPayments;
use App\Support\Decimal;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BudgetItem extends Model
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
        'target_amount' => 'decimal:2',
        'invested_amount' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:3',
        'date' => 'date',
    ];

    /** Kept for its callers. Nothing emails on a save any more (the review's PB-12): the committee report is the summary. */
    public static bool $notify = true;

    /** A paid-out figure sent with this save, posted as a row of the difference after it (prepare → finalizer): BudgetPayments. */
    public ?string $pendingSpent = null;

    //boot
    protected static function boot()
    {
        parent::boot();

        //disable deleting
        static::deleting(function ($model) {
            //throw new BusinessRuleException('Deleting is not allowed');
            \App\Services\Budget\EventClosure::assertOpen($model->budget_program_id);
        });

        static::deleted(function ($model) {
            if (BudgetPayments::ready()) {
                (new BudgetPayments())->moveOff($model, $model->changed_by_id ? (int) $model->changed_by_id : null); // money paid out never leaves the totals with its line
            }
            self::categoryOf($model)?->updateSelf(); // the totals follow, whoever removed it
        });

        static::creating(function ($model) {

            $model->name = trim($model->name);
            $withSameName = BudgetItem::where([
                'name' => $model->name,
                'budget_item_category_id' => $model->budget_item_category_id,
            ])->first();

            if ($withSameName) {
                throw new BusinessRuleException('Name already exists');
            }

            $model = self::prepare($model);

            return $model;
        });

        static::updating(function ($model) {
            $model->name = trim($model->name);
            $withSameName = BudgetItem::where([
                'name' => $model->name,
                'budget_item_category_id' => $model->budget_item_category_id,
            ])->where('id', '!=', $model->id)->first();
            if ($withSameName) {
                throw new BusinessRuleException('Name already exists');
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
        // Exact: unit price × quantity in bcmath (DECIMAL(20,2) × DECIMAL(15,3)), never floats.
        $data->unit_price = Decimal::valid($data->unit_price) ? Decimal::of((string) $data->unit_price) : '0.00';
        $data->quantity = Decimal::valid($data->quantity) ? Decimal::of((string) $data->quantity, 3) : '0.000';
        if (($data->isDirty('unit_price') || $data->isDirty('quantity')) && (Decimal::cmp($data->unit_price, '0') < 0 || Decimal::cmp($data->quantity, '0') < 0)) {
            throw BusinessRuleException::make('validation', 'A price and a quantity cannot be below zero.'); // the forms and the API say so too; a pushed op reaches here
        }
        $data->target_amount = Decimal::mul($data->unit_price, $data->quantity);

        // Use auth user first for company_id (most reliable), fallback to created_by_id
        $loggedUser = null;
        $authUser = auth('admin')->user();
        if ($authUser !== null) {
            $loggedUser = $authUser;
        }
        if ($loggedUser === null && ! empty($data->created_by_id)) {
            $loggedUser = User::find($data->created_by_id);
        }
        if ($loggedUser === null) {
            throw new BusinessRuleException('Cannot determine user — no authenticated user and created_by_id is invalid.');
        }

        $data->company_id = $loggedUser->company_id;
        $data->changed_by_id = $loggedUser->id;

        // Keep created_by_id pointing to original creator on create, update changed_by_id on edit
        if (empty($data->created_by_id)) {
            $data->created_by_id = $loggedUser->id;
        }

        $cat = self::categoryOf($data);
        if ($cat == null) {
            throw new BusinessRuleException('Category not found');
        }

        // Always sync budget_program_id from the category to prevent mismatch
        $data->budget_program_id = $cat->budget_program_id;
        \App\Services\Budget\EventClosure::assertOpen($data->budget_program_id);
        if ($data->exists && $data->isDirty('budget_program_id')) {
            \App\Services\Budget\EventClosure::assertOpen($data->getOriginal('budget_program_id'));
        }

        // invested_amount is the sum of the line's budget_payments and is never set by hand. A figure sent with the save (an older phone, the
        // classic form) becomes a row of the difference once the line is saved (finalizer).
        if (BudgetPayments::ready()) {
            $payments = new BudgetPayments();
            $wanted = $data->isDirty('invested_amount') && Decimal::valid($data->invested_amount) ? Decimal::max(Decimal::of((string) $data->invested_amount), '0') : null;
            if ($data->exists) {
                $payments->adopt((int) $data->id);
            }
            $paid = $data->exists ? $payments->sum((int) $data->id) : '0.00';
            $data->pendingSpent = $wanted !== null && Decimal::cmp($wanted, $paid) !== 0 ? $wanted : null;
            $data->invested_amount = $paid;
        } elseif ($data->invested_amount === null) {
            $data->invested_amount = 0;
        }

        return $data;
    }

    /** A line's category, even one removed since: a line that outlives its category still counts, and can be edited and moved. */
    private static function categoryOf($item): ?BudgetItemCategory
    {
        return BudgetItemCategory::withoutGlobalScope(\App\Scopes\TombstoneScope::class)->find($item->budget_item_category_id);
    }

    //public static function finalizer
    public static function finalizer($data)
    {
        if (BudgetPayments::ready()) {
            $wanted = $data->pendingSpent;
            $data->pendingSpent = null;
            $payments = new BudgetPayments();
            if ($wanted !== null) {
                $payments->settleTo((int) $data->company_id, (int) $data->id, $wanted, $data->changed_by_id ? (int) $data->changed_by_id : null);
            } elseif (! $data->wasRecentlyCreated) {
                $payments->recalculateById((int) $data->company_id, (int) $data->id);
            }
            // The caller's model shows what was stored (the figure, sync version).
            $stored = DB::table($data->getTable())->where('id', $data->id)->first(['invested_amount', 'version', 'server_seq', 'client_updated_at']);
            if ($stored !== null) {
                foreach ((array) $stored as $k => $v) {
                    $data->setAttribute($k, $v);
                }
            }
        }

        $target = Decimal::valid($data->target_amount) ? (string) $data->target_amount : '0';
        $invested = Decimal::valid($data->invested_amount) ? (string) $data->invested_amount : '0';
        $balance = Decimal::sub($target, $invested);
        $percentage_done = Decimal::percent($invested, $target);
        $is_complete = Decimal::cmp($percentage_done, '98') >= 0 ? 'Yes' : 'No';

        DB::table((new self())->getTable())
            ->where('id', $data->id)
            ->update([
                'balance' => $balance,
                'percentage_done' => $percentage_done,
                'is_complete' => $is_complete,
            ]);

        $cat = self::categoryOf($data);

        if ($cat) {
            try {
                $cat->updateSelf();
            } catch (\Throwable $th) {
                Log::error('BudgetItem::finalizer - category update failed for category #'.$data->budget_item_category_id.': '.$th->getMessage());
            }
        } else {
            Log::warning('BudgetItem::finalizer - orphaned item #'.$data->id.' has no valid category #'.$data->budget_item_category_id);
        }
    }

    /**
     * Put an event's lines (or one category's) in the order given: phones get it with their next pull.
     *
     * @param  list<int>  $ids
     */
    public static function arrange(int $companyId, array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            $line = self::withoutGlobalScopes()->without(['budgetItemCategory', 'createdBy'])->where('company_id', $companyId)->find($id);
            if ($line !== null && (int) $line->sort_order !== $i + 1) {
                $line->sort_order = $i + 1;
                $line->saveQuietlySynced();
            }
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
        // $with eager-loads the `budgetItemCategory` relation, not `category`
        // (both point at the same FK) -- using `category` here silently
        // re-queried on every single serialized BudgetItem (N+1 on every
        // API list/show call) despite the eager-load already being declared.
        if ($this->budgetItemCategory == null) {
            return 'N/A';
        }

        return $this->budgetItemCategory->name;
    }

    //appends budget_item_category_text
    protected $appends = ['budget_item_category_text'];
}

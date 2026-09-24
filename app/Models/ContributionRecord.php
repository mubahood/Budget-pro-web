<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContributionRecord extends Model
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
    protected $with = ['budgetProgram', 'treasurer', 'changedBy'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    //boot
    protected static function boot()
    {
        parent::boot();

        //disable deleting
        static::deleting(function ($model) {
            throw new BusinessRuleException('Deleting is not allowed');
        });

        static::creating(function ($model) {

            $model->name = trim($model->name);
            $withSameName = ContributionRecord::where([
                'name' => $model->name,
                'budget_program_id' => $model->budget_program_id,
            ])->first();
            if ($withSameName) {
                throw new BusinessRuleException('Name already exists');
            }

            $model = self::prepare($model);

            return $model;
        });

        static::updating(function ($model) {
            $model->name = trim($model->name);
            $withSameName = ContributionRecord::where([
                'name' => $model->name,
                'budget_program_id' => $model->budget_program_id,
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
        // Use auth user as primary source for company_id (most reliable)
        $authUser = auth('admin')->user();
        $loggedUser = null;

        if ($authUser !== null) {
            $loggedUser = $authUser;
        }

        // Validate treasurer exists
        $treasurer = User::find($data->treasurer_id);
        if ($treasurer === null) {
            // Fallback: if no treasurer specified, use auth user
            if ($loggedUser !== null) {
                $data->treasurer_id = $loggedUser->id;
                $treasurer = $loggedUser;
            } else {
                throw new BusinessRuleException('Treasurer/User not found for ID: '.$data->treasurer_id);
            }
        }

        // Set company_id from auth user first, fallback to treasurer
        if ($loggedUser !== null) {
            $data->company_id = $loggedUser->company_id;

            // Validate treasurer belongs to the same company
            if ($treasurer->company_id != $loggedUser->company_id) {
                throw new BusinessRuleException('Treasurer does not belong to your company.');
            }
        } else {
            $data->company_id = $treasurer->company_id;
        }

        $custom_amount = (int) $data->custom_amount;
        if ($custom_amount > 0) {
            $data->amount = $custom_amount;
        }
        $custom_paid_amount = (int) $data->custom_paid_amount;
        if ($custom_paid_amount > 0) {
            $data->paid_amount = $custom_paid_amount;
        }

        // Captured BEFORE the normalization below touches paid_amount
        // unconditionally (which would otherwise make isDirty() true even
        // when the caller never sent a real figure at all).
        $paidAmountExplicitlySet = $data->isDirty('paid_amount');

        // Ensure amount is non-negative
        $data->amount = max(0, (int) $data->amount);

        // Ensure paid_amount is non-negative and cannot exceed pledged amount
        $data->paid_amount = max(0, (int) $data->paid_amount);
        if ($data->paid_amount > $data->amount && $data->amount > 0) {
            $data->paid_amount = $data->amount;
        }

        if ($data->fully_paid == 'Yes') {
            // Only default paid_amount up to the full pledge when THIS save
            // didn't also carry a real paid_amount/custom_paid_amount — a
            // "mark as fully paid" shortcut that sends only the flag still
            // works, but a caller that submits fully_paid=Yes alongside a
            // genuine (lower) receipt figure no longer has that real figure
            // silently overwritten to the full pledge amount.
            if (! $paidAmountExplicitlySet) {
                $data->paid_amount = $data->amount;
            }
            $data->not_paid_amount = max(0, $data->amount - $data->paid_amount);
        } else {
            $data->not_paid_amount = $data->amount - $data->paid_amount;
        }

        if ($data->paid_amount >= $data->amount && $data->amount > 0) {
            $data->fully_paid = 'Yes';
        } else {
            $data->fully_paid = 'No';
        }
        if ($data->fully_paid == 'Yes') {
            $data->not_paid_amount = 0;
        }

        // Track who last modified this record
        if ($loggedUser !== null) {
            $data->chaned_by_id = $loggedUser->id;
        }

        return $data;
    }

    //public function finalizer
    public static function finalizer($data)
    {
        $table_name = (new self)->getTable();
        //sql set custom_paid_amount to null and custom_amount to null
        $sql = "UPDATE $table_name SET custom_paid_amount = NULL, custom_amount = NULL WHERE id = ?";
        DB::update($sql, [$data->id]);

        // Cascade up to parent BudgetProgram to update contribution totals
        try {
            if ($data->budget_program_id) {
                BudgetProgram::recalculateFromChildren((int) $data->budget_program_id);
            }
        } catch (\Throwable $th) {
            Log::error('ContributionRecord::finalizer cascade to program failed: '.$th->getMessage());
        }

        return $data;
    }

    //get treasurer initials
    public function tr()
    {
        $treasurer = User::find($this->treasurer_id);
        if ($treasurer == null) {
            return '';
        }
        //first letter of first name
        $first = substr($treasurer->name, 0, 1);

        return strtoupper($first);
    }

    //appends for treasurer_text
    protected $appends = ['treasurer_text', 'chaned_by_text'];

    //getter for treasurer_text
    public function getTreasurerTextAttribute()
    {
        // `treasurer` is already eager-loaded via $with -- a fresh
        // User::find() here re-queried on every serialized record (N+1 on
        // every list/show call).
        if ($this->treasurer == null) {
            return 'N/A';
        }

        return $this->treasurer->name;
    }

    //getter for chaned_by_text
    public function getChanedByTextAttribute()
    {
        if ($this->changedBy == null) {
            return 'N/A';
        }

        return $this->changedBy->name;
    }

    /**
     * Relationships
     */
    public function budgetProgram()
    {
        return $this->belongsTo(BudgetProgram::class, 'budget_program_id');
    }

    public function treasurer()
    {
        return $this->belongsTo(User::class, 'treasurer_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'chaned_by_id');
    }

    public function financialPeriod()
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
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

    public function scopeByPeriod($query, $periodId)
    {
        return $query->where('financial_period_id', $periodId);
    }

    public function scopeFullyPaid($query)
    {
        return $query->where('fully_paid', 'Yes');
    }

    public function scopeNotFullyPaid($query)
    {
        return $query->where('fully_paid', 'No');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
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

    public function scopeByTreasurer($query, $treasurerId)
    {
        return $query->where('treasurer_id', $treasurerId);
    }
}

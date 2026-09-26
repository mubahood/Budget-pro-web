<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Jobs\UpdateFinancialCategoryAggregates;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class FinancialRecord extends Model
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
    protected $with = ['financial_category', 'createdBy'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'date' => 'datetime',
        'amount' => 'decimal:2',
        'quantity' => 'decimal:3',
        'is_reversal' => 'boolean',
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
        'source_type',
        'source_id',
        'is_reversal',
        'reverses_id',
        'currency',
    ];

    //boot
    protected static function boot()
    {
        parent::boot();

        //creating
        static::creating(function (FinancialRecord $model) {
            $user = $model->created_by_id ? User::withoutGlobalScopes()->find($model->created_by_id) : null;
            if ($user === null) {
                throw BusinessRuleException::make('invalid_user', 'Invalid user for this financial record.');
            }
            // Manual entries are stamped with the creator's company; system entries (payments,
            // stock movements) already carry the tenant and must not be re-stamped.
            if (empty($model->company_id)) {
                $model->company_id = $user->company_id;
            }
            $amount = (float) $model->amount;
            if ($amount == 0.0 || ($amount < 0 && ! $model->is_reversal)) {
                throw BusinessRuleException::make('invalid_amount', 'Amount must be greater than zero.');
            }
            if (empty($model->date)) {
                $model->date = now();
            }
            if (empty($model->currency)) {
                $model->currency = Company::withoutGlobalScopes()->find($model->company_id)?->currency;
            }
            // Period is derived from the business date (P0-10), never from "whatever is active today".
            $period = FinancialPeriod::resolveFor((int) $model->company_id, \Illuminate\Support\Carbon::parse($model->date));
            $model->financial_period_id = $period->id;
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
        static::updating(function (FinancialRecord $model) {
            if (! empty($model->source_type) && $model->isDirty(['amount', 'type', 'financial_category_id', 'date', 'source_type', 'source_id', 'company_id'])) {
                throw BusinessRuleException::make('ledger_locked', 'This ledger entry was posted by the system (sale/payment). Reverse the payment instead of editing it.');
            }
            $period = FinancialPeriod::withoutGlobalScopes()->find($model->getOriginal('financial_period_id') ?? $model->financial_period_id);
            if ($period !== null && $period->status === 'Closed') {
                throw BusinessRuleException::make('period_closed', 'Cannot update a record in a closed financial period.');
            }
            // A new date belongs to the period that contains it (which must itself be open).
            if ($model->isDirty('date') && $model->date) {
                $model->financial_period_id = FinancialPeriod::resolveFor((int) $model->company_id, \Illuminate\Support\Carbon::parse($model->date))->id;
            }
            if ((float) $model->amount <= 0 && ! $model->is_reversal) {
                throw BusinessRuleException::make('invalid_amount', 'Amount must be greater than zero.');
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

        static::deleting(function (FinancialRecord $model) {
            if (! empty($model->source_type)) {
                throw BusinessRuleException::make('ledger_locked', 'System-posted ledger entries cannot be deleted. Reverse the payment or void the sale instead.');
            }
            $period = FinancialPeriod::withoutGlobalScopes()->find($model->financial_period_id);
            if ($period !== null && $period->status === 'Closed') {
                throw BusinessRuleException::make('period_closed', 'Cannot delete a record in a closed financial period.');
            }
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
    public function financial_category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class);
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function company(): BelongsTo
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

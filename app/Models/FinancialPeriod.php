<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Financial Period Model
 *
 * Represents an accounting period for financial transactions.
 * Only one period can be active per company at a time.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon $end_date
 * @property string $status Active|Closed
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Company $company
 */
class FinancialPeriod extends Model
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
     * The relationships that should always be loaded.
     */
    protected $with = ['company'];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'company_id',
        'name',
        'start_date',
        'end_date',
        'status',
        'description',
        'closed_at',
        'closed_by_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
    ];

    /**
     * Boot method for model events.
     * Ensures only one active financial period per company.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function (FinancialPeriod $model) {
            if (! empty($model->getAttribute('start_date')) && ! empty($model->getAttribute('end_date')) && Carbon::parse($model->end_date)->lt(Carbon::parse($model->start_date))) {
                throw BusinessRuleException::make('invalid_period_range', 'The period end date must be on or after the start date.');
            }
            if ($model->status === 'Closed' && empty($model->closed_at)) {
                $model->closed_at = now();
                $model->closed_by_id = $model->closed_by_id ?? auth()->id();
            }
            if ($model->status !== 'Closed') {
                $model->closed_at = null;
                $model->closed_by_id = null;
            }
        });

        // Activating a period deactivates the previous one — the generated `active_flag`
        // unique index guarantees a single Active row per company even under races.
        static::saved(function (FinancialPeriod $model) {
            if ($model->status === 'Active') {
                DB::table('financial_periods')
                    ->where('company_id', $model->company_id)
                    ->where('id', '!=', $model->id)
                    ->where('status', 'Active')
                    ->update(['status' => 'Inactive', 'updated_at' => now()]);
            }
        });
    }

    /** Insert with the right conflict semantics: activating while another is Active demotes it first. */
    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            if ($this->status === 'Active' && $this->company_id) {
                DB::table('financial_periods')
                    ->where('company_id', $this->company_id)
                    ->when($this->exists, fn ($q) => $q->where('id', '!=', $this->id))
                    ->where('status', 'Active')
                    ->update(['status' => 'Inactive', 'updated_at' => now()]);
            }

            return parent::save($options);
        });
    }

    /**
     * The period a business date belongs to (P0-10): the period whose range
     * contains the date, preferring Active; falls back to the Active period.
     * Closed periods are refused.
     */
    public static function resolveFor(int $companyId, Carbon $date): self
    {
        $period = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderByRaw("status = 'Active' DESC")
            ->orderByDesc('id')
            ->first();

        if ($period === null) {
            $period = static::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'Active')->first();
        }
        if ($period === null) {
            throw BusinessRuleException::make('no_active_period', 'No active financial period. Please create/activate one first.');
        }
        if ($period->status === 'Closed') {
            throw BusinessRuleException::make('period_closed', 'The financial period for '.$date->toDateString().' is closed.', ['period_id' => $period->id]);
        }

        return $period;
    }

    public function isClosed(): bool
    {
        return $this->status === 'Closed';
    }

    /**
     * Relationships
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class, 'financial_period_id');
    }

    public function stockRecords(): HasMany
    {
        return $this->hasMany(StockRecord::class, 'financial_period_id');
    }

    public function financialRecords(): HasMany
    {
        return $this->hasMany(FinancialRecord::class, 'financial_period_id');
    }

    public function budgetItems(): HasMany
    {
        return $this->hasMany(BudgetItem::class, 'financial_period_id');
    }

    public function budgetPrograms(): HasMany
    {
        return $this->hasMany(BudgetProgram::class, 'financial_period_id');
    }

    public function contributionRecords(): HasMany
    {
        return $this->hasMany(ContributionRecord::class, 'financial_period_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Query Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'Active');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'Closed');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'Inactive');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->where('start_date', '>=', $startDate)
            ->where('end_date', '<=', $endDate);
    }

    public function scopeCurrent($query)
    {
        return $query->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->where('status', 'Active');
    }

    public function scopeThisYear($query)
    {
        return $query->whereYear('start_date', now()->year);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class FinancialPeriod extends Model
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
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    //boot
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            //active financial period
            $active_financial_period = FinancialPeriod::where([
                'company_id' => $model->company_id,
                'status' => 'Active',
            ])->first();
            if ($active_financial_period != null && $model->status == 'Active') {
                throw new \Exception('There is an active financial period. Please close it first.');
            }
        });


        static::updating(function ($model) {
            //active financial period
            $active_financial_period = FinancialPeriod::where([
                'company_id' => $model->company_id,
                'status' => 'Active',
            ])->first();
            if ($model->status == 'Active') {
                if ($active_financial_period != null && $active_financial_period->id != $model->id) {
                    throw new \Exception('There is an active financial period. Please close it first.');
                }
            }
        });
    }

    /**
     * Relationships
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function stockItems()
    {
        return $this->hasMany(StockItem::class, 'financial_period_id');
    }

    public function stockRecords()
    {
        return $this->hasMany(StockRecord::class, 'financial_period_id');
    }

    public function financialRecords()
    {
        return $this->hasMany(FinancialRecord::class, 'financial_period_id');
    }

    public function budgetItems()
    {
        return $this->hasMany(BudgetItem::class, 'financial_period_id');
    }

    public function budgetPrograms()
    {
        return $this->hasMany(BudgetProgram::class, 'financial_period_id');
    }

    public function contributionRecords()
    {
        return $this->hasMany(ContributionRecord::class, 'financial_period_id');
    }

    public function createdBy()
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

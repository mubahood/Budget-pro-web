<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialCategory extends Model
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
    protected $with = ['company'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = ['company_id', 'name', 'type', 'status', 'created_by_id', 'description'];

    //boot
    protected static function boot()
    {
        parent::boot();

        static::saving(function (FinancialCategory $model) {
            $model->name = trim((string) $model->name);
            if ($model->name === '') {
                throw BusinessRuleException::make('name_required', 'Category name is required.');
            }
            if (empty($model->company_id)) {
                $model->company_id = auth()->user()?->company_id;
            }
            if (empty($model->type)) {
                $model->type = in_array(strtolower($model->name), ['sales', 'income', 'other income'], true) ? 'Income' : 'Expense';
            }
            if (! in_array($model->type, ['Income', 'Expense'], true)) {
                throw BusinessRuleException::make('invalid_type', 'Category type must be Income or Expense.');
            }
            if (empty($model->status)) {
                $model->status = 'Active';
            }
            $dup = static::withoutGlobalScopes()
                ->where('company_id', $model->company_id)
                ->whereRaw('LOWER(name) = ?', [strtolower($model->name)])
                ->when($model->exists, fn ($q) => $q->where('id', '!=', $model->id))
                ->exists();
            if ($dup) {
                throw BusinessRuleException::make('duplicate_category', 'A category named "'.$model->name.'" already exists.');
            }
        });
    }

    /**
     * Relationships
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function financialRecords(): HasMany
    {
        return $this->hasMany(FinancialRecord::class, 'financial_category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
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
}

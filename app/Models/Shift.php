<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Till session / cash-up (plan A3). */
class Shift extends Model
{
    use Syncable;

    protected $table = 'shifts';

    protected $fillable = ['uuid', 'company_id', 'number', 'opened_by_id', 'opened_at', 'opening_float', 'status', 'notes', 'device_id', 'created_by_id'];

    protected $casts = ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'opening_float' => 'decimal:2', 'expected_cash' => 'decimal:2', 'counted_cash' => 'decimal:2', 'variance' => 'decimal:2', 'sales_total' => 'decimal:2', 'is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(SaleRecord::class, 'shift_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }
}

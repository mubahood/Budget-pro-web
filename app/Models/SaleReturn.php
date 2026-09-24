<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Return / refund against a sale (plan A1, P2-6). */
class SaleReturn extends Model
{
    use Syncable;

    protected $table = 'sale_returns';

    protected $fillable = ['uuid', 'company_id', 'sale_record_id', 'reason', 'value', 'refund_amount', 'refund_method', 'shift_id', 'device_id', 'created_by_id'];

    protected $casts = ['value' => 'decimal:2', 'refund_amount' => 'decimal:2', 'is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(SaleRecord::class, 'sale_record_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'sale_return_id');
    }
}

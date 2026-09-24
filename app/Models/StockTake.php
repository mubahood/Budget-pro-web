<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stock count session (plan A4, P2-7). */
class StockTake extends Model
{
    use Syncable;

    protected $table = 'stock_takes';

    protected $fillable = ['uuid', 'company_id', 'number', 'name', 'stock_category_id', 'status', 'notes', 'device_id', 'created_by_id'];

    protected $casts = ['posted_at' => 'datetime', 'is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTakeItem::class, 'stock_take_id');
    }
}

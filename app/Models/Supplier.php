<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Vendor; balance = unpaid goods receipts minus supplier payments (SupplierService). */
class Supplier extends Model
{
    use Syncable;

    protected $table = 'suppliers';

    protected $fillable = ['uuid', 'company_id', 'name', 'phone', 'email', 'address', 'payment_terms_days', 'lead_time_days', 'notes', 'is_active', 'created_by_id'];

    protected $casts = ['balance' => 'decimal:2', 'is_active' => 'boolean', 'is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        // Empty terms mean the defaults (the columns are NOT NULL): pay on delivery, 7 days to deliver.
        static::saving(function (Supplier $s) {
            $s->payment_terms_days ??= 0;
            $s->lead_time_days ??= 7;
        });
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'supplier_id');
    }
}

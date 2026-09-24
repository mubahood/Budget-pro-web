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

    protected $fillable = ['uuid', 'company_id', 'name', 'phone', 'email', 'address', 'payment_terms_days', 'notes', 'is_active', 'created_by_id'];

    protected $casts = ['balance' => 'decimal:2', 'is_active' => 'boolean', 'is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'supplier_id');
    }
}

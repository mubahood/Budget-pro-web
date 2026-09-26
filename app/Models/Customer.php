<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Buyer with a credit book (plan A1 'debt book'); balance is derived by CustomerService. */
class Customer extends Model
{
    use Syncable;

    protected $table = 'customers';

    protected $fillable = ['uuid', 'company_id', 'name', 'phone', 'email', 'address', 'credit_limit', 'payment_terms_days', 'reminders_enabled', 'notes', 'is_active', 'created_by_id'];

    protected $casts = ['credit_limit' => 'decimal:2', 'balance' => 'decimal:2', 'is_active' => 'boolean', 'is_deleted' => 'boolean', 'reminders_enabled' => 'boolean', 'payment_terms_days' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(SaleRecord::class, 'customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'customer_id');
    }
}

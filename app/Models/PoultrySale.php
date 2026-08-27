<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\PoultrySyncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoultrySale extends Model
{
    use AuditLogger, HasFactory, PoultrySyncable;

    protected static array $syncColumns = [
        'category', 'product_label', 'qty', 'unit', 'unit_price', 'total', 'amount_paid', 'date', 'note',
    ];

    protected static array $syncUuidRefs = [
        'customer_uuid' => ['model' => PoultryCustomer::class, 'column' => 'customer_id'],
        'batch_uuid' => ['model' => PoultryBatch::class, 'column' => 'batch_id'],
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $casts = [
        'date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(PoultryCustomer::class, 'customer_id');
    }

    public function batch()
    {
        return $this->belongsTo(PoultryBatch::class, 'batch_id');
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->total - (float) $this->amount_paid;
    }
}

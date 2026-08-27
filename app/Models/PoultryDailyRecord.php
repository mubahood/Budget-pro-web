<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\PoultrySyncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoultryDailyRecord extends Model
{
    use AuditLogger, HasFactory, PoultrySyncable;

    protected static array $syncColumns = [
        'date', 'eggs_trays', 'eggs_loose', 'mortality', 'feed_kg', 'water_l',
        'notes', 'egg_unit_price', 'feed_price_per_kg', 'avg_weight_kg',
    ];

    protected static array $syncUuidRefs = [
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

    public function batch()
    {
        return $this->belongsTo(PoultryBatch::class, 'batch_id');
    }

    public function getTotalEggsAttribute(): int
    {
        return (int) $this->eggs_trays * 30 + (int) $this->eggs_loose;
    }
}

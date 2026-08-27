<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\PoultrySyncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoultryExpense extends Model
{
    use AuditLogger, HasFactory, PoultrySyncable;

    protected static array $syncColumns = ['category', 'amount', 'date', 'note'];

    protected static array $syncUuidRefs = [
        'feed_type_uuid' => ['model' => PoultryFeedType::class, 'column' => 'feed_type_id'],
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

    public function feedType()
    {
        return $this->belongsTo(PoultryFeedType::class, 'feed_type_id');
    }

    public function batch()
    {
        return $this->belongsTo(PoultryBatch::class, 'batch_id');
    }
}

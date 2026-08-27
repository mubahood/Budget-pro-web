<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\PoultrySyncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoultryVaccinationEvent extends Model
{
    use AuditLogger, HasFactory, PoultrySyncable;

    protected static array $syncColumns = [
        'vaccine', 'method', 'age_days', 'withdrawal_days', 'due_date', 'done', 'done_date', 'note',
    ];

    protected static array $syncUuidRefs = [
        'batch_uuid' => ['model' => PoultryBatch::class, 'column' => 'batch_id'],
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $casts = [
        'due_date' => 'date',
        'done_date' => 'date',
        'done' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(PoultryBatch::class, 'batch_id');
    }
}

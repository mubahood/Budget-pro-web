<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\PoultrySyncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoultryEggTransaction extends Model
{
    use AuditLogger, HasFactory, PoultrySyncable;

    protected static array $syncColumns = ['type', 'eggs', 'date', 'note'];

    protected static array $syncUuidRefs = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $casts = [
        'date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

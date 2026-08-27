<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\PoultrySyncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoultryFeedType extends Model
{
    use AuditLogger, HasFactory, PoultrySyncable;

    protected static array $syncColumns = ['name', 'category', 'kg_per_bag'];

    protected static array $syncUuidRefs = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function feedStock()
    {
        return $this->hasMany(PoultryFeedStock::class, 'feed_type_id');
    }
}

<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\PoultrySyncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoultryCustomer extends Model
{
    use AuditLogger, HasFactory, PoultrySyncable;

    protected static array $syncColumns = ['name', 'phone', 'notes'];

    protected static array $syncUuidRefs = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sales()
    {
        return $this->hasMany(PoultrySale::class, 'customer_id');
    }
}

<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Unit of measure (plan A1): a pack is `factor` base units, so one stock serves piece and carton sales. */
class Unit extends Model
{
    use Syncable;

    protected $table = 'units';

    protected $fillable = ['uuid', 'company_id', 'name', 'abbreviation', 'base_unit_id', 'factor', 'created_by_id'];

    protected $casts = ['factor' => 'decimal:3', 'is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_unit_id');
    }
}

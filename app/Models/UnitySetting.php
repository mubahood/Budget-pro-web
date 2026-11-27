<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a group's own settings, one row per group (verify_page: the public card check is on). */
class UnitySetting extends Model
{
    protected $table = 'unity_settings';

    protected $guarded = ['id'];

    protected $casts = ['verify_page' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public static function for(int $companyId): self
    {
        return self::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId], ['verify_page' => true]);
    }
}

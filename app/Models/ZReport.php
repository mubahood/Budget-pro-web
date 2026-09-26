<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A closed day (supermarket plan E2): numbered without gaps (NumberSequencer 'z'), one per shop
 * and day (per location when the shop uses locations), and immutable once written.
 */
class ZReport extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'z_reports';

    protected $guarded = ['id'];

    protected $casts = ['totals' => 'array', 'business_date' => 'date', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(fn () => throw BusinessRuleException::make('z_report_locked', 'A Z report cannot be changed once the day is closed.'));
        static::deleting(fn () => throw BusinessRuleException::make('z_report_locked', 'A Z report cannot be deleted.'));
    }
}

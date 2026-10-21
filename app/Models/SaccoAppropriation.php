<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/** A year's surplus appropriation (P4.7): the waterfall as asked, approved by two officers and posted once (approp:{year}). */
class SaccoAppropriation extends Model
{
    use AuditLogger;

    public const STATUSES = ['requested', 'posted', 'rejected', 'cancelled', 'expired'];

    protected $guarded = ['id'];

    protected $casts = ['lines' => 'array', 'warnings' => 'array', 'agm_date' => 'date:Y-m-d', 'posted_on' => 'date:Y-m-d', 'surplus_minor' => 'integer',
        'dividend_rate_bp' => 'integer', 'dividend_pool_minor' => 'integer', 'fiscal_year_id' => 'integer', 'entry_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

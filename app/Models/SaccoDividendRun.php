<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A dividend paid out (P4.8): the declared pool shared exactly over the members' shares, approved by two officers. */
class SaccoDividendRun extends Model
{
    use AuditLogger;

    public const STATUSES = ['requested', 'paid', 'rejected', 'cancelled', 'expired'];

    public const BASES = ['year_end_shares', 'weighted_monthly'];

    public const PAY_TO = ['savings', 'cash', 'momo'];

    protected $guarded = ['id'];

    protected $casts = ['warnings' => 'array', 'registrar_consent_on' => 'date:Y-m-d', 'paid_on' => 'date:Y-m-d', 'rate_bp' => 'integer', 'pool_minor' => 'integer',
        'basis_total_minor' => 'integer', 'members_count' => 'integer', 'core_capital_ratio_bp_after' => 'integer', 'fiscal_year_id' => 'integer', 'entry_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaccoDividendLine::class, 'run_id');
    }
}

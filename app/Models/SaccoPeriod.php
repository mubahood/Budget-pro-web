<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A calendar month of a SACCO's books (ADR 0009 §8, AK-53). Postings land only in an open period; closing and
 * reopening go through App\Services\Sacco\Ledger\PeriodService (with maker-checker).
 */
class SaccoPeriod extends Model
{
    use AuditLogger;

    public const STATUSES = ['open', 'closing', 'closed'];

    protected $guarded = ['id'];

    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'closed_at' => 'datetime', 'reopened_at' => 'datetime',
        'year' => 'integer', 'month' => 'integer', 'snapshot_no' => 'integer', 'reopen_count' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function label(): string
    {
        return $this->starts_on->format('F Y');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(SaccoPeriodBalance::class, 'period_id');
    }
}

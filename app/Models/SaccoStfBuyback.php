<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/** Shares bought back from a leaving member by the Share Transfer Fund (P4.9), through an approval. */
class SaccoStfBuyback extends Model
{
    use AuditLogger;

    public const STATUSES = ['requested', 'posted', 'rejected', 'cancelled', 'expired'];

    protected $guarded = ['id'];

    protected $casts = ['warnings' => 'array', 'posted_on' => 'date:Y-m-d', 'shares_count' => 'integer', 'amount_minor' => 'integer', 'fiscal_year' => 'integer',
        'member_id' => 'integer', 'share_account_id' => 'integer', 'entry_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

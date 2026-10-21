<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A SACCO's ledger head (ADR 0009 §4.3, §6): locked FOR UPDATE by every posting, which serialises a SACCO's
 * postings and gives the hash chain its order; it holds the chain's last hash. Written only by PostingService
 * (and created with the chart).
 */
class SaccoLedgerHead extends Model
{
    protected $primaryKey = 'company_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['entries_count' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

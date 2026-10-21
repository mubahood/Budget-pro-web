<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One statement file (bank, MTN, Airtel, Flutterwave) for 1100, 1150 or 1160 (P3.4): Reconciliation\StatementImport writes it. */
class SaccoStatementImport extends Model
{
    public const SOURCES = ['bank', 'mtn', 'airtel', 'flutterwave'];

    protected $guarded = ['id'];

    protected $casts = ['period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'closing_balance_minor' => 'integer', 'lines_count' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

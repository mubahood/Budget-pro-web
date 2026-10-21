<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One version of a savings product's terms (04 §3.3): written once by SavingsProducts, never changed. */
class SaccoSavingsProductVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['terms' => 'array', 'effective_from' => 'date:Y-m-d', 'terms_version' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('product_history', 'A product\'s past terms are kept as they were.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('product_history', 'A product\'s past terms are kept as they were.');
        });
    }
}

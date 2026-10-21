<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** A kind of the SACCO's own money in or out (bank charges, office expenses, a grant, other income): the SACCO's own list. */
class SaccoAccountCategory extends Model
{
    public const DIRECTIONS = ['in', 'out'];

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'ledger_account_id' => 'integer', 'sort' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}

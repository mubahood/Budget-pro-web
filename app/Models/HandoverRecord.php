<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HandoverRecord extends Model
{
    use AuditLogger, HasFactory;

    /**
     * Unlike its four Budget-module siblings, this model had no tenant
     * scoping at all — the admin grid showed every company's handover
     * records to any logged-in admin. Matches BudgetProgram/BudgetItem/
     * BudgetItemCategory/ContributionRecord's own CompanyScope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $fillable = [
        'company_id', 'budget_program_id', 'from_id', 'to_id',
        'details', 'transfer_date', 'to_approved', 'amount',
    ];
}

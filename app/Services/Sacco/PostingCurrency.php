<?php

namespace App\Services\Sacco;

use App\Models\Company;
use App\Models\SaccoSetting;
use App\Support\Sacco\Currency;

/** The currency a SACCO keeps its books in: fixed in its settings at setup, the company's until then. */
final class PostingCurrency
{
    public static function of(int $companyId): string
    {
        $fixed = SaccoSetting::withoutGlobalScopes()->where('company_id', $companyId)->value('currency');
        if (is_string($fixed) && $fixed !== '') {
            return Currency::code($fixed);
        }

        return Currency::code((string) (Company::withoutGlobalScopes()->whereKey($companyId)->value('currency') ?: config('saas.default_currency', 'UGX')));
    }

    public static function forCompany(Company $company): string
    {
        return Currency::code((string) ($company->currency ?: config('saas.default_currency', 'UGX')));
    }
}

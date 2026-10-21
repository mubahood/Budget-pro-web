<?php

namespace App\Services\Sacco\Loans;

use App\Models\Company;
use App\Services\Sacco\SaccoSetupService;

/**
 * Wires loans (P2) into the rest of the SACCO module at boot, called once from SaccoModule::boot, so the shared boot
 * file needs one line and every loan registration lives here.
 */
final class LoansModule
{
    public static function boot(): void
    {
        SaccoSetupService::extend('loan_products', fn (Company $company) => (new LoanProducts())->ensureDefaults($company));
    }
}

<?php

namespace App\Services\Sacco\Loans;

use App\Models\Company;
use App\Services\Sacco\Ledger\SubLedgers;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoSetupService;

/**
 * Wires loans (P2) into the rest of the SACCO module at boot, called once from SaccoModule::boot, so the shared boot
 * file needs one line and every loan registration lives here.
 */
final class LoansModule
{
    /** The loan maker-checker actions (04 §7). */
    public const EXECUTORS = [Executors\LoanDecisionExecutor::class, Executors\LoanDisbursementExecutor::class];

    public static function boot(): void
    {
        SubLedgers::register(new LoanLedger());
        foreach (self::EXECUTORS as $class) {
            SaccoApprovals::register(new $class());
        }
        \App\Services\Sacco\Payouts\PayoutService::onResult('loan_disbursement', [Disbursement::class, 'payoutResult']);
        SaccoSetupService::extend('loan_products', fn (Company $company) => (new LoanProducts())->ensureDefaults($company));
    }
}

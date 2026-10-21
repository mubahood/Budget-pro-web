<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLoan;
use App\Models\SaccoPeriod;
use App\Services\Sacco\Ledger\CloseChecks;
use App\Services\Sacco\Ledger\SubLedgers;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoSetupService;
use App\Services\Sacco\SaccoTransactions;

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
        SaccoTransactions::recorder('loan_repayment', [Repayments::class, 'recorder']);
        SaccoTransactions::offlineCheck('loan_repayment', [Repayments::class, 'offlineCheck']);
        SaccoTransactions::onReverse('loan_repayment', 'loans', [Repayments::class, 'reversed']);
        CloseChecks::register('loans', function (int $companyId, SaccoPeriod $period) {
            $behind = SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'active')->where('disbursed_on', '<=', $period->ends_on->toDateString())
                ->where(fn ($q) => $q->whereNull('arrears_on')->orWhere('arrears_on', '<=', $period->ends_on->toDateString()))->count();
            if ($behind > 0) {
                throw BusinessRuleException::make('loan_runs_pending', "{$behind} loan(s) are not brought up to the end of ".$period->label().' yet: run sacco:daily first.');
            }
        });
        SaccoSetupService::extend('loan_products', fn (Company $company) => (new LoanProducts())->ensureDefaults($company));
    }
}

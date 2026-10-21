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
    public const EXECUTORS = [Executors\LoanDecisionExecutor::class, Executors\LoanDisbursementExecutor::class, Executors\WriteOffExecutor::class,
        Executors\GuarantorRecoveryExecutor::class, Executors\RescheduleExecutor::class, Executors\MemberExitExecutor::class];

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
        LoanJobs::onMonthEnd('provisioning', fn (int $companyId, string $month) => (new Provisioning())->run($companyId, $month));
        CloseChecks::register('provisioning', function (int $companyId, SaccoPeriod $period) {
            $month = $period->starts_on->format('Y-m');
            $lent = SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('disbursed_on')->where('disbursed_on', '<=', $period->ends_on->toDateString())->exists();
            if ($lent && ! LoanBooks::settings($companyId)->isGroup() && ! \App\Models\SaccoProvisionRun::withoutGlobalScopes()->where('company_id', $companyId)->where('month', $month)->exists()) {
                throw BusinessRuleException::make('provisioning_pending', 'The loan-loss provisioning for '.$period->label().' has not run yet: run sacco:daily on the 1st.');
            }
        });
        SaccoTransactions::approvalCheck('loan_arrears', [self::class, 'withdrawalBlock']);
        SaccoSetupService::extend('loan_products', fn (Company $company) => (new LoanProducts())->ensureDefaults($company));
    }

    /**
     * Coop s.55AK, AK-30: a member in arrears, or whose guaranteed loan is in arrears, withdraws only with a second
     * officer's approval (the approval, with its reason, is the override).
     */
    public static function withdrawalBlock(int $companyId, \App\Models\SaccoSavingsAccount $account, int $amount): ?string
    {
        $own = SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $account->member_id)->where('status', 'active')->where('days_past_due', '>', 0)->value('loan_no');
        if ($own !== null) {
            return "The member's loan {$own} is in arrears (s.55AK first charge).";
        }
        $guaranteed = \App\Models\SaccoLoanGuarantor::withoutGlobalScopes()->from('sacco_loan_guarantors as g')->join('sacco_loans as l', 'l.id', '=', 'g.loan_id')
            ->where('g.company_id', $companyId)->where('g.guarantor_member_id', $account->member_id)->where('g.consent_status', 'consented')
            ->where('l.status', 'active')->where('l.days_past_due', '>', 0)->value('l.loan_no');

        return $guaranteed !== null ? "A loan the member guarantees ({$guaranteed}) is in arrears (s.55AK)." : null;
    }
}

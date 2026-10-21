<?php

namespace App\Services\Sacco\YearEnd;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoInterestRun;
use App\Models\SaccoPeriod;
use App\Models\SaccoSavingsAccount;
use App\Services\Sacco\Ledger\CloseChecks;
use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\SaccoApprovals;

/**
 * Wires P4 (reports, returns, year-end) into the rest of the SACCO module at boot, called once from SaccoModule::boot,
 * so every P4 registration lives here: the executors, the month-end savings interest, the close checks and the reports.
 */
final class YearEndModule
{
    /** The P4 maker-checker actions (04 §7). */
    public const EXECUTORS = [Executors\InterestRunExecutor::class, Executors\YearEndCloseExecutor::class, Executors\AppropriationExecutor::class,
        Executors\DividendRunExecutor::class, Executors\StfBuybackExecutor::class, Executors\DormantTransferExecutor::class];

    public static function boot(): void
    {
        foreach (self::EXECUTORS as $class) {
            SaccoApprovals::register(new $class());
        }
        // The loan book's daily run ends each month with its month-end work; savings interest is part of it (04 §16).
        LoanJobs::onMonthEnd('savings_interest', fn (int $companyId, string $month) => self::ready() ? (new SavingsInterest())->run($companyId, $month) : null);
        // Dormancy is counted in months: the month-end puts quiet accounts on the register (P4.10).
        LoanJobs::onMonthEnd('dormancy', fn (int $companyId, string $month) => self::ready() ? (new Dormancy())->run($companyId, \Illuminate\Support\Carbon::parse($month.'-01')->endOfMonth()->toDateString()) : null);
        \App\Services\Sacco\Payouts\PayoutService::onResult('dividend', [Dividends::class, 'payoutResult']);
        // ADR 0009 §8: a month closes only once its savings interest is worked out.
        CloseChecks::register('savings_interest', [self::class, 'interestCheck']);
    }

    /** Whether the P4 tables exist (a database not yet migrated to P4 runs everything else as before). */
    public static function ready(): bool
    {
        return self::$ready ??= \Illuminate\Support\Facades\Schema::hasTable('sacco_interest_runs');
    }

    private static ?bool $ready = null;

    public static function interestCheck(int $companyId, SaccoPeriod $period): void
    {
        if (! self::ready()) {
            return;
        }
        $interest = new SavingsInterest();
        $end = $period->ends_on->copy()->startOfDay();
        foreach ($interest->products($companyId) as $product) {
            [$rate, $basis] = $interest->terms($product, $end);
            if ($rate <= 0 || $basis === 'none') {
                continue;
            }
            $held = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('product_id', $product->id)
                ->where('opened_on', '<=', $end->toDateString())->where(fn ($q) => $q->whereNull('closed_on')->orWhere('closed_on', '>=', $period->starts_on->toDateString()))->exists();
            if ($held && ! SaccoInterestRun::withoutGlobalScopes()->where('company_id', $companyId)->where('product_id', $product->id)->where('kind', 'accrual')
                ->where('period_from', $period->starts_on->toDateString())->exists()) {
                throw BusinessRuleException::make('interest_pending', 'The savings interest for '.$period->label().' has not been worked out yet: run the month end first.');
            }
        }
    }
}

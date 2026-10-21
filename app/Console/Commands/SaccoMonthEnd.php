<?php

namespace App\Console\Commands;

use App\Models\SaccoSetting;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Loans\LoanJobs;
use App\Support\LocalDate;
use Illuminate\Console\Command;

/**
 * The 1st of each month at 02:30 (04 §16): the months ahead opened, and the loan book's month-end work (last month's
 * penalty and provisioning, and the savings interest of P4.6) done if the daily run has not done it yet. Idempotent:
 * every posting has its month's key.
 */
class SaccoMonthEnd extends Command
{
    protected $signature = 'sacco:month-end {--company=}';

    protected $description = 'Open the coming SACCO months and finish the loan book\'s month end';

    public function handle(LoanJobs $jobs, PeriodService $periods): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')] : SaccoSetting::withoutGlobalScopes()->orderBy('company_id')->pluck('company_id')->all();
        foreach ($ids as $cid) {
            try {
                $periods->ensureAhead((int) $cid, LocalDate::today((int) $cid), (int) config('sacco.defaults.periods_ahead', 3));
                $jobs->run((int) $cid);
                if (\App\Services\Sacco\YearEnd\YearEndModule::ready()) {
                    (new \App\Services\Sacco\YearEnd\SavingsInterest())->catchUp((int) $cid); // P4.6: a SACCO with no loans still earns interest on savings
                }
                $this->line("SACCO {$cid}: month end done.");
            } catch (\Throwable $e) {
                report($e);
                $this->error("SACCO {$cid}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}

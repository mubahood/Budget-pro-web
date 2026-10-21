<?php

namespace App\Console\Commands;

use App\Models\SaccoSetting;
use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Payouts\PayoutService;
use Illuminate\Console\Command;

/**
 * Daily at 01:30 (04 §16): every SACCO's loan book brought up to today (interest due, arrears and class, the 1st's
 * penalty and provisioning, reminders), catching up any missed nights, and the payout poll as a fallback.
 */
class SaccoDaily extends Command
{
    protected $signature = 'sacco:daily {--company=} {--date= : run up to this day (default today)}';

    protected $description = 'Bring SACCO loans up to date: interest, arrears, classification, penalties, provisioning, reminders';

    public function handle(LoanJobs $jobs, PayoutService $payouts): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')] : SaccoSetting::withoutGlobalScopes()->orderBy('company_id')->pluck('company_id')->all();
        foreach ($ids as $cid) {
            try {
                $r = $jobs->run((int) $cid, $this->option('date') ?: null);
                $this->line("SACCO {$cid}: {$r['loans']} loan(s), {$r['days']} day(s), interest {$r['interest_minor']}, penalty {$r['penalty_minor']}.");
            } catch (\Throwable $e) {
                report($e);
                $this->error("SACCO {$cid}: ".$e->getMessage());
            }
        }
        $payouts->poll();

        return self::SUCCESS;
    }
}

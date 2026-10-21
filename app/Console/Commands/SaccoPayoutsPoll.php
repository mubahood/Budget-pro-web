<?php

namespace App\Console\Commands;

use App\Services\Sacco\Payouts\PayoutService;
use Illuminate\Console\Command;

/** Every 5 minutes (04 §11.2): ask the provider about payouts still in flight; after 24 hours they wait for a person. */
class SaccoPayoutsPoll extends Command
{
    protected $signature = 'sacco:payouts-poll {--company=}';

    protected $description = 'Ask the payout provider about SACCO payouts still in flight';

    public function handle(PayoutService $payouts): int
    {
        $this->info($payouts->poll($this->option('company') ? (int) $this->option('company') : null).' payout(s) settled or failed.');

        return self::SUCCESS;
    }
}

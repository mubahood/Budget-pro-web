<?php

namespace App\Console\Commands;

use App\Services\Rentals\Jobs\RentalCheck;
use App\Services\Rentals\Jobs\RentalDaily;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Nightly at 03:20 (docs/estate-ledger/04-architecture.md §5.12): prove every Estate Ledger account's books. The latest result is
 * cached for System health; a failure is logged and sent to the error tracker with the ids to look at.
 */
class RentalsCheck extends Command
{
    protected $signature = 'rentals:check {--company= : Check one account only}';

    protected $description = 'Estate Ledger: every journal row balances and every cache equals what it caches';

    public const CACHE_KEY = 'rentals:check';

    public function handle(RentalCheck $check): int
    {
        $ids = $this->option('company') !== null ? [(int) $this->option('company')] : RentalDaily::companyIds();
        $failed = [];
        foreach ($ids as $id) {
            $problems = $check->run($id);
            if ($problems !== []) {
                $failed[$id] = $problems;
                foreach ($problems as $pr) {
                    $this->error("Account #{$id}: {$pr['detail']} (ids ".implode(', ', $pr['ids']).')');
                }
                Log::critical('[rentals] books check failed', ['company_id' => $id, 'problems' => $problems]);
                if (class_exists(ErrorTracker::class)) {
                    ErrorTracker::capture(new \RuntimeException("Estate Ledger books check failed for company {$id}: ".implode(', ', array_column($problems, 'check'))));
                }
            }
        }
        Cache::put(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'accounts' => count($ids), 'failed' => $failed], now()->addDays(3));
        if ($failed === []) {
            $this->info(count($ids).' account(s): the books agree.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}

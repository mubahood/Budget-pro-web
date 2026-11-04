<?php

namespace App\Console\Commands;

use App\Services\Rentals\Jobs\RentalDaily;
use App\Support\LocalDate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly at 01:40 (docs/estate-ledger/04-architecture.md §13): raise every Estate Ledger account's bills that fall within its
 * horizon and its late fees. Idempotent, so a missed night is caught up by the next run.
 */
class RentalsDaily extends Command
{
    protected $signature = 'rentals:daily {--company= : One account only} {--date= : Run as if today were this day (Y-m-d)}';

    protected $description = 'Estate Ledger: raise rent bills that come due, and late fees';

    public function handle(RentalDaily $daily): int
    {
        $ids = $this->option('company') !== null ? [(int) $this->option('company')] : RentalDaily::companyIds();
        $bills = 0;
        $fees = 0;
        foreach ($ids as $id) {
            $day = $this->option('date') ? Carbon::parse((string) $this->option('date')) : LocalDate::today($id);
            try {
                $r = $daily->run($id, $day);
                $bills += $r['bills'];
                $fees += $r['late_fees'];
            } catch (\Throwable $e) {
                report($e);
                $this->error("Account #{$id}: {$e->getMessage()}");
            }
        }
        $this->info(count($ids)." account(s): {$bills} bill(s) raised, {$fees} late fee(s).");

        return self::SUCCESS;
    }
}

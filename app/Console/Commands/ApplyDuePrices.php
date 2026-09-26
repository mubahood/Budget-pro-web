<?php

namespace App\Console\Commands;

use App\Services\Shop\PriceBookService;
use Illuminate\Console\Command;

/** Scheduled price changes whose time has come are applied (PriceBookService::applyDue). Idempotent. */
class ApplyDuePrices extends Command
{
    protected $signature = 'prices:apply-due {--company= : One shop only}';

    protected $description = 'Apply scheduled price changes that are due, and queue their shelf labels';

    public function handle(PriceBookService $prices): int
    {
        $n = $prices->applyDue($this->option('company') ? (int) $this->option('company') : null);
        $this->info("Applied {$n} price change(s).");

        return self::SUCCESS;
    }
}

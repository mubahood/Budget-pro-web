<?php

namespace App\Console\Commands;

use App\Services\Shop\ProductStatsService;
use Illuminate\Console\Command;

/** Nightly sales velocity per product (plan A7, P4-3). */
class RefreshProductStats extends Command
{
    protected $signature = 'shop:product-stats {--company= : Only this company id}';

    protected $description = 'Recompute 7/30/90-day sales per product for reorder suggestions';

    public function handle(ProductStatsService $stats): int
    {
        $n = $stats->refresh($this->option('company') ? (int) $this->option('company') : null);
        $this->info("{$n} products updated.");

        return self::SUCCESS;
    }
}

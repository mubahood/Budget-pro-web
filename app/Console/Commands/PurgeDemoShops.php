<?php

namespace App\Console\Commands;

use App\Services\Onboarding\DemoShopService;
use Illuminate\Console\Command;

/** Hourly: delete demo shops older than DemoShopService::KEEP_DAYS days (POWER_PLAN §3.1). */
class PurgeDemoShops extends Command
{
    protected $signature = 'onboarding:purge-demos';

    protected $description = 'Delete demo shops older than '.DemoShopService::KEEP_DAYS.' days';

    public function handle(DemoShopService $demos): int
    {
        $n = $demos->purgeExpired();
        $this->info("{$n} demo shop(s) deleted.");

        return self::SUCCESS;
    }
}

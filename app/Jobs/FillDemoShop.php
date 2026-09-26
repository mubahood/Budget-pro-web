<?php

namespace App\Jobs;

use App\Services\Onboarding\DemoShopService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Fills a new demo shop with about two months of trading (DemoShopService::fill), off the web request. */
class FillDemoShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $demoId, public int $days = 60)
    {
    }

    public function handle(DemoShopService $demos): void
    {
        @set_time_limit(300);
        $demos->fillById($this->demoId, $this->days);
    }
}

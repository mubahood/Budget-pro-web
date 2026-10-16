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
        // A web request filling a demo needs the room; a limit set here stays on the whole process,
        // so it is put back afterwards (a long queue worker or the test suite must not inherit it).
        $previous = (int) ini_get('max_execution_time');
        @set_time_limit(300);
        try {
            $demos->fillById($this->demoId, $this->days);
        } finally {
            @set_time_limit($previous);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Every minute on a standalone copy (docs/STANDALONE_EDITION_PLAN.md §16): proof that the cron runs.
 * The owner's "scheduler not running" banner and Licence & updates read the key; it lapses after
 * ten minutes without a run, which is how a missing or wrong cron line shows up. Scheduled only on
 * standalone (app/Console/Kernel.php).
 *
 * The web app keeps its own cache, so the beat is also a small file both apps read
 * (App\Support\SchedulerHeartbeat: system/heartbeat.json beside the install marker).
 */
class StandaloneHeartbeat extends Command
{
    public const KEY = 'standalone:scheduler-heartbeat';

    protected $signature = 'standalone:heartbeat';

    protected $description = 'Record that the scheduler ran (standalone edition)';

    public function handle(): int
    {
        Cache::put(self::KEY, now()->toIso8601String(), now()->addMinutes(SchedulerHeartbeat::STALE_MINUTES));
        SchedulerHeartbeat::record();

        return self::SUCCESS;
    }
}

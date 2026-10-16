<?php

namespace App\Console\Commands;

use App\Services\Billing\Lifecycle;
use App\Services\Notifications\ScheduledNotifications;
use Illuminate\Console\Command;

/** Hourly: billing lifecycle + time-of-day shop notifications (plan C7/C8). Safe to run any number of times. */
class DispatchNotifications extends Command
{
    protected $signature = 'saas:hourly';

    protected $description = 'Advance subscriptions (trial → Free, past due, dunning) and send scheduled shop notifications';

    public function handle(Lifecycle $lifecycle, ScheduledNotifications $notifications): int
    {
        \Illuminate\Support\Facades\Cache::forever('heartbeat:saas_hourly', now()->toIso8601String()); // System health: the scheduler is alive
        // Trial → Free, renewals and dunning are SaaS only: a standalone copy runs on a lifetime plan (§16).
        $billing = \App\Support\Edition::isSaas() ? $lifecycle->run() : ['skipped' => 'standalone'];
        $sent = $notifications->run();
        $sent['debt_reminders'] = app(\App\Services\Engage\DebtReminders::class)->run();
        $this->info('billing '.json_encode($billing).' notices '.json_encode($sent));

        return self::SUCCESS;
    }
}

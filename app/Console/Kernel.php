<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        $schedule->command('tracking:backfill-location-names')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('saas:hourly')->hourly()->withoutOverlapping();
        // Messages (OTP, invites, notices) go through the database queue; cron runs the worker each minute.
        $schedule->command('queue:work --stop-when-empty --tries=3 --max-time=50')->everyMinute()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

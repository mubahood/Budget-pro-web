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
        // Every task runs inside this scheduler process (no child process per command): the shared host
        // allows 25 processes per account and spawning failed at busy minutes (DECISIONS E52).
        $task = fn (string $command, array $args = []) => fn () => \Illuminate\Support\Facades\Artisan::call($command, $args);
        // queue:work asks Symfony whether `stty` exists, which runs a shell command; at busy minutes the host
        // refuses it and the warning stopped the worker for that minute. Scheduled tasks have no terminal anyway.
        \Closure::bind(fn () => self::$stty = false, null, \Symfony\Component\Console\Terminal::class)();

        $schedule->call($task('tracking:backfill-location-names'))->name('tracking:backfill-location-names')->everyFiveMinutes()->withoutOverlapping();
        $schedule->call($task('saas:hourly'))->name('saas:hourly')->hourly()->withoutOverlapping();
        $schedule->call($task('billing:reconcile'))->name('billing:reconcile')->hourlyAt(20)->withoutOverlapping();
        $schedule->call($task('onboarding:purge-demos'))->name('onboarding:purge-demos')->hourlyAt(20)->withoutOverlapping();
        $schedule->call($task('shop:product-stats'))->name('shop:product-stats')->dailyAt('01:30')->withoutOverlapping();
        $schedule->call($task('ops', ['task' => 'backup']))->name('ops backup')->dailyAt('02:00')->withoutOverlapping();
        $schedule->call($task('ops', ['task' => 'drill']))->name('ops drill')->weeklyOn(0, '03:30')->withoutOverlapping();
        $schedule->call($task('ops', ['task' => 'purge']))->name('ops purge')->dailyAt('04:00')->withoutOverlapping();
        // Messages (OTP, invites, notices) go through the database queue; the worker drains it each minute.
        $schedule->call($task('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--max-time' => 50]))->name('queue:work')->everyMinute()->withoutOverlapping();
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

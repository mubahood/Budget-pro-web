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

        // Per edition (docs/STANDALONE_EDITION_PLAN.md §16): the shop's own jobs run on both; billing, demos,
        // Ping Pin, the mysqldump backup and the automatic purge are SaaS only.
        $saas = \App\Support\Edition::isSaas();
        if ($saas) {
            $schedule->call($task('tracking:backfill-location-names'))->name('tracking:backfill-location-names')->everyFiveMinutes()->withoutOverlapping();
        }
        $schedule->call($task('prices:apply-due'))->name('prices:apply-due')->everyFiveMinutes()->withoutOverlapping();
        $schedule->call($task('fiscal:submit-due'))->name('fiscal:submit-due')->everyMinute()->withoutOverlapping(); // F2: nothing to do unless a shop has fiscal receipts on
        $schedule->call($task('saas:hourly'))->name('saas:hourly')->hourly()->withoutOverlapping(); // standalone: notices + debt reminders only, no billing Lifecycle
        $schedule->call($task('customers:adopt-named'))->name('customers:adopt-named')->hourlyAt(40)->withoutOverlapping();
        $schedule->call($task('shop:product-stats'))->name('shop:product-stats')->dailyAt('01:30')->withoutOverlapping();
        // Akiba Ledger (ADR 0009 §7): every SACCO's books proved sound each night; failures reach the owner and System health.
        $schedule->call($task('sacco:approvals-expire'))->name('sacco:approvals-expire')->hourlyAt(15)->withoutOverlapping();
        $schedule->call($task('sacco:ledger-verify'))->name('sacco:ledger-verify')->dailyAt('03:10')->withoutOverlapping();
        // Akiba Ledger loans (04 §16): the daily book (interest, arrears, penalties and provisioning on the 1st, reminders), the month end, MoMo payouts in flight.
        $schedule->call($task('sacco:daily'))->name('sacco:daily')->dailyAt('01:30')->withoutOverlapping(120);
        $schedule->call($task('sacco:month-end'))->name('sacco:month-end')->monthlyOn(1, '02:30')->withoutOverlapping(120);
        $schedule->call($task('sacco:payouts-poll'))->name('sacco:payouts-poll')->everyFiveMinutes()->withoutOverlapping();
        $schedule->call($task('sacco:savings-terms'))->name('sacco:savings-terms')->dailyAt('00:20')->withoutOverlapping();
        $schedule->call($task('api:prune-keys'))->name('api:prune-keys')->dailyAt('03:20')->withoutOverlapping(); // Idempotency-Key answers and web links (D5, D7)
        if ($saas) {
            $schedule->call($task('billing:reconcile'))->name('billing:reconcile')->hourlyAt(20)->withoutOverlapping();
            $schedule->call($task('onboarding:purge-demos'))->name('onboarding:purge-demos')->hourlyAt(20)->withoutOverlapping();
            $schedule->call($task('demo:public'))->name('demo:public')->hourlyAt(5)->withoutOverlapping(90);
            $schedule->call($task('ops', ['task' => 'backup']))->name('ops backup')->dailyAt('02:00')->withoutOverlapping();
            $schedule->call($task('ops', ['task' => 'drill']))->name('ops drill')->weeklyOn(0, '03:30')->withoutOverlapping();
            $schedule->call($task('ops', ['task' => 'purge']))->name('ops purge')->dailyAt('04:00')->withoutOverlapping();
            // Release builds run from their own cron line (`releases:work`, every 2 minutes), never in here;
            // this only fails a build whose worker died so it can be retried.
            $schedule->call(fn () => \App\Models\StandaloneRelease::failStale())->name('standalone-release-stale-check')->everyTenMinutes();
        } else {
            // Minute by minute, for the owner's "scheduler not running" banner (StandaloneHeartbeat).
            $schedule->call($task('standalone:heartbeat'))->name('standalone:heartbeat')->everyMinute();
        }
        // Messages (OTP, invites, notices) go through the database queue; the worker drains it each minute.
        $schedule->call($task('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--max-time' => 50]))->name('queue:work')->everyMinute()->withoutOverlapping();
        if (! $saas) {
            // Licence & updates (§12): what the page asked for (an update or a backup) within a minute, last in the run;
            // the nightly backup; the daily update check. Maintenance mode skips them, as it does every task.
            $schedule->call($task('standalone:system-requests'))->name('standalone:system-requests')->everyMinute()->withoutOverlapping(60);
            $schedule->call($task('standalone:backup'))->name('standalone:backup')->dailyAt('02:15')->withoutOverlapping(120);
            $schedule->call($task('standalone:update', ['--check' => true]))->name('standalone:update --check')->dailyAt('06:10')->withoutOverlapping(30);
        }
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

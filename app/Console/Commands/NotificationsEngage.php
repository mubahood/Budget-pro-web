<?php

namespace App\Console\Commands;

use App\Services\Notifications\Reminders;
use Illuminate\Console\Command;

/**
 * Every 20 minutes (:20 and :40): the scheduled notices of each app (rent and loans falling due, memberships ending, family
 * birthdays, the flock, app updates, the Sunday week). Each fires once per account and day (or week), so the repeats are safe.
 */
class NotificationsEngage extends Command
{
    protected $signature = 'notifications:engage';

    protected $description = 'Send the scheduled reminders and digests of each app (once per account and period)';

    public function handle(Reminders $reminders): int
    {
        $counts = $reminders->run();
        $this->info($counts === [] ? 'Nothing to send.' : collect($counts)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}

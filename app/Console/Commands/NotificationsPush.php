<?php

namespace App\Console\Commands;

use App\Services\Notifications\Notifier;
use Illuminate\Console\Command;

/** Every 20 minutes (:20 and :40): pushes held back by quiet hours go once the night is over (Notifier::pushDue). */
class NotificationsPush extends Command
{
    protected $signature = 'notifications:push {--limit=300}';

    protected $description = 'Send the push notifications that waited for quiet hours to end';

    public function handle(Notifier $notifier): int
    {
        $this->info('Pushed: '.$notifier->pushDue(max(1, (int) $this->option('limit'))));

        return self::SUCCESS;
    }
}

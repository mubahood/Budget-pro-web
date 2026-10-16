<?php

namespace App\Console\Commands;

use App\Jobs\BuildStandaloneRelease;
use App\Support\Edition;
use Illuminate\Console\Command;

/**
 * The standalone release worker (docs/STANDALONE_EDITION_PLAN.md §10, §16). SaaS host only, from
 * its OWN cron line, never from the in-process scheduler (a build takes minutes and the till's jobs
 * must not wait on it):
 *
 *   * /2 * * * * cd ~/budget-pro && php artisan releases:work >/dev/null 2>&1
 *
 * Takes queued builds from the release-builds connection, then exits when there are none. The
 * 35-minute lease (retry_after 2100) keeps a second worker off a build that is still running.
 */
class ReleasesWork extends Command
{
    protected $signature = 'releases:work';

    protected $description = 'Build queued standalone releases, then stop (SaaS release cron)';

    public function handle(): int
    {
        if (! Edition::isSaas()) {
            $this->error('Releases are built on the SaaS only.');

            return self::FAILURE;
        }
        // As in the scheduler: queue:work asks Symfony whether `stty` exists, which the host may refuse at busy minutes.
        \Closure::bind(fn () => self::$stty = false, null, \Symfony\Component\Console\Terminal::class)();

        return $this->call('queue:work', [
            'connection' => BuildStandaloneRelease::CONNECTION, '--queue' => BuildStandaloneRelease::QUEUE, '--stop-when-empty' => true,
            '--max-time' => 1850, '--timeout' => 1800, '--tries' => 1,
        ]);
    }
}

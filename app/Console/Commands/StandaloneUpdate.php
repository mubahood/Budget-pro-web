<?php

namespace App\Console\Commands;

use App\Services\Install\StandaloneUpdater;
use App\Services\Install\SystemState;
use App\Services\Install\UpdateChannel;
use App\Support\Edition;
use Illuminate\Console\Command;

/**
 * Update a standalone copy from the command line (docs/STANDALONE_EDITION_PLAN.md §12.2): a downloaded
 * package, or the newest one the licence may fetch online. `--check` only asks (scheduled daily at
 * 06:10, so Licence & updates can say a release is waiting). The page does the same through the
 * scheduler (standalone:system-requests).
 */
class StandaloneUpdate extends Command
{
    protected $signature = 'standalone:update
        {archive? : Path to a budget-pro-standalone-*.zip}
        {--check : Only say whether an update is available}
        {--online : Download the newest entitled release and install it}';

    protected $description = 'Verify and install a newer standalone release, with a backup and automatic rollback';

    public function handle(StandaloneUpdater $updater, UpdateChannel $channel): int
    {
        if (! Edition::isStandalone()) {
            $this->error('standalone:update is for standalone installations; the SaaS is deployed with deploy.sh.');

            return self::FAILURE;
        }

        $downloaded = null;
        try {
            if ($this->option('check') || $this->option('online')) {
                $latest = $channel->check();
                if ($latest === null) {
                    $this->info('This installation is up to date ('.Edition::version().').');

                    return self::SUCCESS;
                }
                $this->info("Budget Pro {$latest['version']} is available (this copy runs ".Edition::version().').');
                if ($this->option('check')) {
                    return self::SUCCESS;
                }
                $archive = $downloaded = $channel->download($latest['download_url'], $latest['sha256']);
            } else {
                $archive = (string) $this->argument('archive');
                if ($archive === '') {
                    $this->error('Give the path to an update file, or use --online.');

                    return self::FAILURE;
                }
            }

            if ((new SystemState)->busy()) {
                $this->error('An update or backup asked for on the Licence & updates page is waiting or running. Let it finish first.');

                return self::FAILURE;
            }
            $checked = $updater->inspect($archive);
            $this->line("Verified {$checked['version']} ({$checked['files']} files). Updating: both apps are in maintenance mode until it finishes.");
            $result = $updater->apply($archive);
            $this->info("Updated {$result['from']} → {$result['to']}. Backup: {$result['backup']}");

            return self::SUCCESS;
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('The update stopped and the previous release was put back: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($downloaded !== null) {
                @unlink($downloaded);
            }
        }
    }
}

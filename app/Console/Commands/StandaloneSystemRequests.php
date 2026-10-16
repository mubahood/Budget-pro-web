<?php

namespace App\Console\Commands;

use App\Services\Install\InstallationBackups;
use App\Services\Install\StandaloneUpdater;
use App\Services\Install\SystemState;
use App\Services\Install\UpdateChannel;
use App\Support\Edition;
use Illuminate\Console\Command;

/**
 * Carry out what Settings › Licence & updates asked for: an update or a backup
 * (docs/STANDALONE_EDITION_PLAN.md §12.2). Scheduled every minute on a standalone copy, so the web
 * page never runs long work inside a request. One request at a time, claimed atomically.
 */
class StandaloneSystemRequests extends Command
{
    protected $signature = 'standalone:system-requests';

    protected $description = 'Run the update or backup requested from the Licence & updates page (standalone only)';

    public function handle(SystemState $state, StandaloneUpdater $updater, UpdateChannel $channel, InstallationBackups $backups): int
    {
        if (! Edition::isStandalone()) {
            return self::SUCCESS;
        }
        $request = $state->claim();
        if ($request === null) {
            return self::SUCCESS;
        }

        $by = is_int($request['by'] ?? null) ? $request['by'] : null;
        $archive = null;

        try {
            switch ($request['action'] ?? null) {
                case 'backup':
                    $state->running('backup', 'Backing up…', $by);
                    $made = $backups->create();
                    $state->finished('backup', 'Backup ready: '.basename($made['path']).' ('.number_format($made['size'] / 1048576, 1).' MB).');
                    break;

                case 'update':
                    $state->running('update', 'Preparing the update…', $by);
                    $archive = is_string($request['archive'] ?? null) ? $request['archive'] : null;
                    if (($request['source'] ?? null) === 'online') {
                        $state->progress('Asking the update server…', []);
                        $latest = $channel->check();
                        if ($latest === null) {
                            $state->finished('update', 'Already up to date.');
                            break;
                        }
                        $state->progress("Downloading Budget Pro {$latest['version']}…", []);
                        $archive = $channel->download($latest['download_url'], $latest['sha256']);
                    }
                    if ($archive === null || ! is_file($archive)) {
                        throw new \DomainException('The update file is missing. Upload it again.');
                    }
                    $updater->apply($archive, $by);
                    break;

                default:
                    $state->failed('unknown', 'An unknown request was ignored.');
            }
        } catch (\Throwable $e) {
            // The updater has already written its own failure with the log; anything that failed before it started is recorded here.
            if (($state->current()['status'] ?? null) !== 'failed') {
                $state->failed((string) ($request['action'] ?? 'request'), $e->getMessage());
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            // A package in the updates folder is used once, whatever happened.
            $folder = realpath(SystemState::updatesDirectory());
            if ($archive !== null && $folder !== false && str_starts_with((string) realpath($archive), $folder.DIRECTORY_SEPARATOR)) {
                @unlink($archive);
            }
        }

        return self::SUCCESS;
    }
}

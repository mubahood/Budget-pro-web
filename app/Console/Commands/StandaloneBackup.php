<?php

namespace App\Console\Commands;

use App\Services\Install\DatabaseBackup;
use App\Services\Install\InstallationBackups;
use App\Support\Edition;
use Illuminate\Console\Command;

/**
 * Back the standalone installation up now: database, both .env files, licence and uploads in one
 * zip under storage/app/private/backups, seven kept (docs/STANDALONE_EDITION_PLAN.md §12.4).
 */
class StandaloneBackup extends Command
{
    protected $signature = 'standalone:backup
        {--web-env= : The web app\'s .env (default: the sibling budget_pro_web/.env)}
        {--media= : The uploads folder (default: the admin disk, the web root\'s storage)}';

    protected $description = 'Back up the database, settings and uploaded files to private storage (standalone only)';

    public function handle(): int
    {
        if (! Edition::isStandalone()) {
            $this->error('standalone:backup is for standalone installations; the SaaS keeps its own backups.');

            return self::FAILURE;
        }

        $backups = $this->option('web-env') || $this->option('media')
            ? new InstallationBackups(
                $this->laravel->make(DatabaseBackup::class),
                $this->option('web-env') ?: null,
                $this->option('media') ?: null,
            )
            : $this->laravel->make(InstallationBackups::class);

        try {
            $made = $backups->create();
        } catch (\Throwable $e) {
            $this->error('The backup failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info('Backup written: '.basename($made['path']).' ('.number_format($made['size'] / 1048576, 1).' MB).');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Ops\BackupService;
use App\Services\Ops\TenantDataService;
use Illuminate\Console\Command;

/** backup:run · backup:drill · tenants:purge-due (plan Part D, P4-6). */
class OpsCommands extends Command
{
    protected $signature = 'ops {task : backup|drill|purge}';

    protected $description = 'Database backup, restore drill, or delete shops whose deletion grace period is over';

    public function handle(BackupService $backups, TenantDataService $tenants): int
    {
        $r = match ($this->argument('task')) {
            'backup' => $backups->run(),
            'drill' => $backups->drill(),
            'purge' => ['ok' => true, 'purged' => $tenants->purgeDue()],
            default => ['ok' => false, 'error' => 'unknown task'],
        };
        $this->line(json_encode($r, JSON_UNESCAPED_SLASHES));

        return ($r['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Platform health at a glance (plan Part D observability): errors, queue, scheduler, sync, backups. */
class HealthService
{
    public function snapshot(): array
    {
        $since = now()->subDay();
        $oldestJob = DB::table('jobs')->min('created_at');
        $heartbeat = Cache::get('heartbeat:saas_hourly');
        $seq = \App\Support\Sync\SyncSequence::current();

        return [
            'errors' => DB::table('error_events')->whereNull('resolved_at')->orderByDesc('last_seen_at')->limit(30)->get(),
            'queue' => ['pending' => DB::table('jobs')->count(), 'failed' => DB::table('failed_jobs')->count(),
                'oldest_minutes' => $oldestJob ? (int) floor((time() - (int) $oldestJob) / 60) : 0],
            'scheduler' => ['last_hourly_run' => $heartbeat, 'late' => $heartbeat === null || now()->diffInMinutes($heartbeat) > 90],
            'sync' => [
                'batches_24h' => DB::table('sync_batches')->where('created_at', '>=', $since)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
                'open_conflicts' => DB::table('sync_conflicts')->where('state', 'open')->count(),
                'devices_silent_3d' => DB::table('devices')->where('status', 'active')->whereNull('revoked_at')->where('last_seen_at', '<', now()->subDays(3))->count(),
                'devices_active_24h' => DB::table('devices')->where('last_seen_at', '>=', $since)->count(),
                'max_pull_lag' => $seq > 0 ? (int) DB::table('devices')->where('last_seen_at', '>=', now()->subDays(3))->selectRaw('MAX(? - COALESCE(last_pull_seq, 0)) AS lag', [$seq])->value('lag') : 0,
            ],
            'messages_24h' => DB::table('message_log')->where('created_at', '>=', $since)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
            'backups' => DB::table('backup_runs')->orderByDesc('id')->limit(10)->get(),
            'last_backup_ok' => DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->max('created_at'),
            'legacy' => \App\Console\Commands\LegacyStatus::status(),
            'tenants' => ['companies' => DB::table('companies')->count(), 'deletions_scheduled' => DB::table('data_requests')->where('kind', 'delete')->where('status', 'scheduled')->count()],
        ];
    }
}

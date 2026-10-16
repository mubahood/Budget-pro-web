<?php

namespace App\Jobs;

use App\Models\StandaloneRelease;
use App\Services\Licensing\Signer;
use App\Services\Releases\CleanInstallProof;
use App\Services\Releases\GitArchiveStager;
use App\Support\Edition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Builds one standalone release (docs/STANDALONE_EDITION_PLAN.md §10): stage both deployed commits
 * and pack the signed ZIP (GitArchiveStager), then install it for real on an empty database
 * (CleanInstallProof). Only then is it "staged", ready for a platform admin to publish.
 *
 * On its own `release-builds` connection (queue `releases`, a 35-minute lease), worked by its own
 * cron line (`php artisan releases:work`), never by the in-process scheduler. SaaS only.
 */
class BuildStandaloneRelease implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CONNECTION = 'release-builds';

    public const QUEUE = 'releases';

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $releaseId)
    {
        $this->onConnection(self::CONNECTION)->onQueue(self::QUEUE);
    }

    public function handle(GitArchiveStager $stager, CleanInstallProof $proof, Signer $signer): void
    {
        if (! Edition::isSaas()) {
            throw new \RuntimeException('A standalone copy cannot build releases.');
        }
        $release = StandaloneRelease::find($this->releaseId);
        if ($release === null || $release->status !== StandaloneRelease::QUEUED) {
            return; // withdrawn, or already taken by another worker
        }
        $release->forceFill(['status' => StandaloneRelease::BUILDING, 'failure' => null])->save();
        $release->step('Starting the build', 1);
        $step = fn (string $what, int $percent) => $release->step($what, $percent);

        try {
            $destination = storage_path('app/private/releases/budget-pro-standalone-'.$release->version.'.zip');
            $built = $stager->build($release->version, $destination, $signer, $step);
            $release->forceFill([
                'commit_kernel' => $built['commits']['kernel'], 'commit_web' => $built['commits']['web'], 'archive_path' => $built['path'],
                'sha256' => $built['sha256'], 'size' => $built['bytes'], 'file_count' => $built['count'], 'staged_at' => now(),
            ])->save();
            $release->step('Built '.$built['count'].' files (web vendor '.$built['vendor'].'); proving it with a clean install', 50);

            $proved = $proof->run($built['path'], $signer, $step);
            $release->forceFill(['status' => StandaloneRelease::STAGED, 'proved_at' => now()])->save();
            $release->step('Ready to publish: clean install passed in '.$proved['seconds'].'s ('.implode(', ', $proved['checks']).')', 100);
        } catch (\Throwable $e) {
            Log::warning('[releases] build failed', ['version' => $release->version, 'error' => $e->getMessage()]);
            $release->forceFill(['status' => StandaloneRelease::FAILED, 'failure' => mb_substr($e->getMessage(), -4000), 'proved_at' => null])->save();
            $release->step('Build failed', (int) ($release->progress['percent'] ?? 0));
            throw $e;
        }
    }

    /** The worker itself died or timed out: whatever is left building is failed (and can be retried). */
    public function failed(\Throwable $exception): void
    {
        StandaloneRelease::query()->whereKey($this->releaseId)->whereIn('status', [StandaloneRelease::QUEUED, StandaloneRelease::BUILDING])
            ->update(['status' => StandaloneRelease::FAILED, 'failure' => mb_substr($exception->getMessage(), -4000), 'updated_at' => now()]);
    }
}

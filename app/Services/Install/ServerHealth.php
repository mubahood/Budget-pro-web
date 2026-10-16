<?php

namespace App\Services\Install;

use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The facts about a standalone copy's own server that Settings › Licence & updates shows
 * (docs/STANDALONE_EDITION_PLAN.md §12.1): the install marker, whether the scheduler is alive, the
 * queue, free disk, the upload limit, the newest backup, and the newer release the last online check
 * found. Read by the web app too, so nothing here uses the kernel's own cache or storage_path().
 */
final class ServerHealth
{
    public function __construct(private readonly InstallationBackups $backups, private readonly UpdateChannel $channel) {}

    /**
     * @return array{installed:array<string,mixed>,heartbeat:?Carbon,queued:?int,failed:?int,diskFree:?float,
     *               uploadLimit:string,postLimit:string,backupAge:?int}
     */
    public function health(): array
    {
        $marker = (string) config('edition.installed_path');
        $installed = is_file($marker) ? json_decode((string) @file_get_contents($marker), true) : null;

        return [
            'installed' => is_array($installed) ? $installed : [],
            'heartbeat' => SchedulerHeartbeat::last(),
            'queued' => rescue(fn () => DB::table('jobs')->count(), null, false),
            'failed' => rescue(fn () => DB::table('failed_jobs')->count(), null, false),
            'diskFree' => ($free = @disk_free_space(SystemState::privateDirectory())) !== false ? (float) $free : null,
            'uploadLimit' => (string) ini_get('upload_max_filesize'),
            'postLimit' => (string) ini_get('post_max_size'),
            'backupAge' => $this->backups->newestAge(),
        ];
    }

    /** The newer release the last online check found (within two days), or null. @return array<string,mixed>|null */
    public function availableUpdate(): ?array
    {
        $latest = $this->channel->lastCheck()['latest'] ?? null;

        return is_array($latest) && is_string($latest['version'] ?? null) && UpdateChannel::newer($latest['version']) ? $latest : null;
    }

    /** The smaller of PHP's upload and post limits, in bytes (0 = no limit). */
    public static function uploadBytes(): int
    {
        $limits = array_filter(array_map([self::class, 'bytes'], [(string) ini_get('upload_max_filesize'), (string) ini_get('post_max_size')]));

        return $limits === [] ? 0 : min($limits);
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}

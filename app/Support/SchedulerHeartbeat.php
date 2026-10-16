<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Proof that the standalone scheduler runs (docs/STANDALONE_EDITION_PLAN.md §16), readable by BOTH apps.
 *
 * The kernel's `standalone:heartbeat` writes it every minute. The kernel and the web app keep separate
 * file caches, so the beat is also a small file beside the install marker (config edition.installed_path,
 * which both apps resolve to the kernel's storage/app/private): system/heartbeat.json. No database: the
 * owner's "scheduler not running" banner must work whatever else is down.
 */
final class SchedulerHeartbeat
{
    /** A beat older than this means the cron line is missing or wrong. */
    public const STALE_MINUTES = 10;

    public static function path(): string
    {
        $configured = config('edition.heartbeat_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : dirname((string) config('edition.installed_path')).'/system/heartbeat.json';
    }

    /** Best effort: a beat that cannot be written must never fail the scheduler run. */
    public static function record(?Carbon $at = null): bool
    {
        $path = self::path();
        try {
            if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
                return false;
            }
            $tmp = $path.'.'.getmypid().'.tmp';
            if (@file_put_contents($tmp, json_encode(['at' => ($at ?? now())->toIso8601String()])) === false) {
                return false;
            }

            return @rename($tmp, $path);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function last(): ?Carbon
    {
        $path = self::path();
        $data = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        if (! is_array($data) || ! is_string($data['at'] ?? null)) {
            return null;
        }
        try {
            return Carbon::parse($data['at']);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function running(): bool
    {
        $last = self::last();

        return $last !== null && $last->gt(now()->subMinutes(self::STALE_MINUTES));
    }
}

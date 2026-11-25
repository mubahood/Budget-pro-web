<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who used the apps, and when: one row per person per day per door (web or phone) in `user_activity_days`, plus
 * admin_users.last_active_at. Written at most once every few minutes per person and door, so it costs nothing on a
 * busy till. Never throws: a failure here must not stop a sale.
 *
 * Callers: the kernel's EnsureApiTenant (every signed-in phone request) and the portal's web middleware.
 */
final class Activity
{
    public const SOURCES = ['web', 'phone'];

    /** Seconds between two writes for one person and door. */
    public const EVERY = 300;

    public static function touch(int $userId, ?int $companyId, string $source, ?string $appVersion = null): void
    {
        if ($userId <= 0 || ! in_array($source, self::SOURCES, true)) {
            return;
        }
        try {
            if (! Cache::add("activity:{$userId}:{$source}", 1, self::EVERY)) {
                return;
            }
            $now = now();
            $version = $appVersion !== null ? mb_substr(preg_replace('/[^0-9A-Za-z.+\-]/', '', $appVersion) ?? '', 0, 20) : null;
            $key = ['user_id' => $userId, 'day' => $now->toDateString(), 'source' => $source];
            $updated = DB::table('user_activity_days')->where($key)->update(array_filter([
                'hits' => DB::raw('hits + 1'), 'last_at' => $now, 'company_id' => $companyId, 'app_version' => $version ?: null,
            ], fn ($v) => $v !== null));
            if ($updated === 0) {
                DB::table('user_activity_days')->insertOrIgnore($key + ['company_id' => $companyId, 'app_version' => $version ?: null,
                    'hits' => 1, 'first_at' => $now, 'last_at' => $now]);
            }
            DB::table('admin_users')->where('id', $userId)->update(['last_active_at' => $now]);
            // An email that asked this person back: mark it answered (the latest one sent before now, once).
            DB::table('engagement_emails')->where('user_id', $userId)->whereNull('returned_at')->where('status', 'sent')
                ->where('sent_at', '>=', $now->copy()->subDays(14))->orderByDesc('id')->limit(1)->update(['returned_at' => $now]);
        } catch (Throwable $e) {
            Log::warning('Activity::touch failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}

<?php

namespace App\Services\Notifications;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How the notifications do, per app and kind (engage plan §2 "Measure"), for Platform Usage beside the Engage emails:
 * inbox entries written and read, pushes sent, and pushes opened by a tap (POST notifications/read with via=push).
 */
final class PushStats
{
    /**
     * @return array<int, array{app: ?string, kind: string, inbox: int, read: int, read_rate: ?float, pushed: int, opened: int, open_rate: ?float, waiting: int, skipped: int}>
     */
    public static function byAppAndKind(Carbon $from, ?Carbon $to = null): array
    {
        $to ??= now();

        return DB::table('app_notifications')->whereBetween('created_at', [$from, $to])
            ->selectRaw("app, type AS kind, COUNT(*) AS inbox, SUM(read_at IS NOT NULL) AS `read`, SUM(push_status = 'sent') AS pushed,
                SUM(push_status = 'sent' AND opened_at IS NOT NULL) AS opened, SUM(push_status = 'pending') AS waiting, SUM(push_status = 'skipped') AS skipped")
            ->groupBy('app', 'type')->orderBy('app')->orderBy('type')->get()
            ->map(function ($r) {
                $inbox = (int) $r->inbox;
                $pushed = (int) $r->pushed;

                return ['app' => $r->app, 'kind' => (string) $r->kind, 'inbox' => $inbox, 'read' => (int) $r->read,
                    'read_rate' => $inbox > 0 ? round((int) $r->read / $inbox, 3) : null, 'pushed' => $pushed, 'opened' => (int) $r->opened,
                    'open_rate' => $pushed > 0 ? round((int) $r->opened / $pushed, 3) : null, 'waiting' => (int) $r->waiting, 'skipped' => (int) $r->skipped];
            })->all();
    }

    /** Phones that can get a push now, per app. @return array<string, int> */
    public static function reachablePhones(): array
    {
        return DB::table('devices')->whereNotNull('push_token')->where('status', '!=', 'revoked')
            ->selectRaw("COALESCE(app, '') AS app, COUNT(*) AS n")->groupBy('app')->pluck('n', 'app')->map(fn ($n) => (int) $n)->all();
    }
}

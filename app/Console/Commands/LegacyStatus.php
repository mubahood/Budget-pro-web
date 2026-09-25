<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Is the old app still in use? (plan B10 step 4: retire the pre-v1 routes below 5 % for 14 days) */
class LegacyStatus extends Command
{
    protected $signature = 'legacy:status {--days=14}';

    protected $description = 'Share of active shops still on the old app, and whether the legacy routes can be retired';

    public function handle(): int
    {
        $s = self::status((int) $this->option('days'));
        $this->table(['Window', 'Shops on old app', 'Shops on new app', 'Old app share', 'Legacy calls'], [[
            $s['days'].' days', $s['legacy_companies'], $s['new_companies'], $s['legacy_percent'].'%', $s['legacy_calls'],
        ]]);
        $this->line($s['recommendation']);

        return self::SUCCESS;
    }

    public static function status(int $days = 14): array
    {
        $since = now()->subDays($days)->toDateString();
        $legacy = DB::table('legacy_calls')->where('day', '>=', $since)->whereNotNull('company_id')->distinct()->pluck('company_id');
        $new = DB::table('devices')->where('last_seen_at', '>=', now()->subDays($days))->distinct()->pluck('company_id');
        $oldOnly = $legacy->diff($new)->count();
        $active = $legacy->merge($new)->unique()->count();
        $pct = $active > 0 ? round($oldOnly * 100 / $active, 1) : 0.0;
        $ready = $pct < (float) config('mobile.legacy_retire_below_percent', 5);

        return ['days' => $days, 'legacy_companies' => $oldOnly, 'new_companies' => $new->count(), 'legacy_percent' => $pct,
            'legacy_calls' => (int) DB::table('legacy_calls')->where('day', '>=', $since)->sum('calls'), 'ready' => $ready,
            'recommendation' => $ready
                ? 'Below the retirement threshold: set LEGACY_API_ENABLED=false (old apps then see "please update"), then remove ApiController/MobileApiController/Utils::get_user in the next release.'
                : 'Keep the legacy routes: shops still use the old app. Remind them to update (Play Store).'];
    }
}

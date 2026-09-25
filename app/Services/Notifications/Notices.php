<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;

/** Makes scheduled notices fire once per company, key and period, however often the scheduler runs. */
class Notices
{
    public static function once(int $companyId, string $key, string $period, callable $send): bool
    {
        $claimed = DB::table('scheduled_notices')->insertOrIgnore(['company_id' => $companyId, 'key' => $key, 'period' => $period, 'created_at' => now()]);
        if ($claimed === 0) {
            return false;
        }
        $send();

        return true;
    }
}

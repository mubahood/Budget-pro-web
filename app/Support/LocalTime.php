<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Company-local dates for SQL (plan C10): timestamps are stored in UTC, so
 * dashboards compare CONVERT_TZ(created_at, '+00:00', @tz_offset) with
 *
 * @local_today instead of CURDATE() on the server clock.
 */
class LocalTime
{
    public static function timezone(?Company $company): string
    {
        $tz = (string) ($company?->timezone ?: config('saas.display_timezone', 'Africa/Kampala'));

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Kampala';
    }

    /** Sets @local_today and @tz_offset on the connection for the queries that follow. */
    public static function prime(int $companyId): void
    {
        $now = now()->setTimezone(self::timezone(Company::withoutGlobalScopes()->find($companyId)));
        DB::statement('SET @local_today = ?, @tz_offset = ?', [$now->toDateString(), $now->format('P')]);
    }
}

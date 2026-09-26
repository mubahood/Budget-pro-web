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

    /** What the connection was last primed with: company, minute, connection. */
    private static ?string $primed = null;

    /** @var array<int, array{0: string, 1: string}> company id => [timezone, minute it was read] from companies loaded this minute */
    private static array $seen = [];

    /** The container the static state belongs to (a new one per test / request in long-lived workers). */
    private static ?int $owner = null;

    /**
     * Sets @local_today and @tz_offset on the connection for the queries that follow.
     *
     * Once per request per company (plan A4): a second call for the same company, on the same
     * connection, within the same minute, is free. Priming another company, a reconnect, a new minute
     * or saving a company (its timezone may have changed) primes again.
     */
    public static function prime(int $companyId): void
    {
        $key = self::key($companyId);
        if ($key !== null && self::$primed === $key) {
            return;
        }
        $known = self::$seen[$companyId] ?? null;
        $tz = $known !== null && $known[1] === now()->format('Y-m-d H:i')
            ? $known[0]
            : self::timezone(Company::withoutGlobalScopes()->find($companyId));
        $now = now()->setTimezone($tz);
        DB::statement('SET @local_today = ?, @tz_offset = ?', [$now->toDateString(), $now->format('P')]);
        self::$primed = $key;
    }

    /** Forget what was primed, so the next prime() runs its query again. */
    public static function forget(): void
    {
        self::$primed = null;
        self::$seen = [];
    }

    /**
     * Wire the listeners for this container (idempotent). Called by each app's AppServiceProvider so the
     * signed-in shop, loaded before anything primes, is already known; prime() calls it too.
     */
    public static function listen(): void
    {
        $app = app();
        if (self::$owner === spl_object_id($app)) {
            return;
        }
        self::$owner = spl_object_id($app);
        self::$primed = null;
        self::$seen = [];
        // A company this request already loaded (the signed-in shop) tells its timezone without another query.
        // Partial selects (no timezone column) are ignored.
        $app['events']->listen('eloquent.retrieved: '.Company::class, function (Company $c) {
            if (array_key_exists('timezone', $c->getAttributes())) {
                self::$seen[(int) $c->getKey()] = [self::timezone($c), now()->format('Y-m-d H:i')];
            }
        });
        $app['events']->listen('eloquent.saved: '.Company::class, fn () => self::forget());
        $app['events']->listen(\Illuminate\Database\Events\ConnectionEstablished::class, fn () => self::forget());
    }

    private static function key(int $companyId): ?string
    {
        try {
            self::listen();
            $conn = DB::connection();
            $pdo = $conn->getPdo();

            return $companyId.'|'.now()->format('Y-m-d H:i').'|'.$conn->getName().'#'.spl_object_id($pdo);
        } catch (\Throwable) {
            return null;
        }
    }
}

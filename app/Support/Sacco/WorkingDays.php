<?php

namespace App\Support\Sacco;

use App\Models\SaccoPublicHoliday;
use App\Services\Sacco\Calendar\PublicHolidays;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Working days in a country (AK-51): Monday to Friday, less its public holidays (sacco_public_holidays). Used for
 * the loan cooling-off (SI 108 reg. 11: at least 5 working days, AK-26) and return due dates. A year the table has
 * no rows for is seeded from the data file on first use, so a date in 2031 never silently counts holidays as work.
 */
final class WorkingDays
{
    /** @var array<string, array<string, true>> "UG:2026" => set of dates */
    private static array $cache = [];

    public static function forget(): void
    {
        self::$cache = [];
    }

    public static function isWorkingDay(CarbonInterface|string $day, string $country = 'UG'): bool
    {
        $d = Carbon::parse($day)->startOfDay();
        if ($d->isWeekend()) {
            return false;
        }

        return ! isset(self::holidays($country, (int) $d->year)[$d->toDateString()]);
    }

    /** The day $n working days after $from ($from itself never counts: signing on Friday, day 1 is Monday). */
    public static function add(CarbonInterface|string $from, int $n, string $country = 'UG'): Carbon
    {
        $d = Carbon::parse($from)->startOfDay();
        $left = max(0, $n);
        while ($left > 0) {
            $d->addDay();
            if (self::isWorkingDay($d, $country)) {
                $left--;
            }
        }

        return $d;
    }

    /** Working days after $from up to and including $to (0 when $to is not after $from). */
    public static function between(CarbonInterface|string $from, CarbonInterface|string $to, string $country = 'UG'): int
    {
        $d = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $n = 0;
        while ($d->lt($end)) {
            $d->addDay();
            $n += self::isWorkingDay($d, $country) ? 1 : 0;
        }

        return $n;
    }

    /** @return array<string, true> */
    private static function holidays(string $country, int $year): array
    {
        $country = strtoupper($country);
        $key = $country.':'.$year;
        if (! isset(self::$cache[$key])) {
            $dates = SaccoPublicHoliday::query()->where('country', $country)->whereYear('date', $year)->pluck('date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
            if ($dates === [] && in_array($country, PublicHolidays::countries(), true)) {
                (new PublicHolidays())->seed($country, $year);
                $dates = SaccoPublicHoliday::query()->where('country', $country)->whereYear('date', $year)->pluck('date')
                    ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
            }
            self::$cache[$key] = array_fill_keys($dates, true);
        }

        return self::$cache[$key];
    }
}

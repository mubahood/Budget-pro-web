<?php

namespace App\Services\Sacco\Calendar;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoPublicHoliday;
use App\Support\Sacco\WorkingDays;
use Illuminate\Support\Carbon;

/**
 * The public holidays table (AK-51): seeded per country and year from database/data/sacco_holidays.php (fixed days,
 * Easter's Good Friday and Monday, the year's Eids), and corrected by the platform admin when a movable feast is
 * gazetted on another day. Seeding never overwrites a day the admin set, so a confirmed Eid stays confirmed.
 */
final class PublicHolidays
{
    /** The data file, found from this class (the portal runs this code with its own database_path()). */
    public static function path(): string
    {
        return dirname(__DIR__, 4).'/database/data/sacco_holidays.php';
    }

    /** @return list<string> the countries the data file knows */
    public static function countries(): array
    {
        return array_keys(require self::path());
    }

    /**
     * Every holiday of a year from the data file.
     *
     * @return list<array{date: string, name: string, kind: string, is_estimate: bool}>
     */
    public static function fromData(string $country, int $year): array
    {
        $data = (require self::path())[strtoupper($country)] ?? throw BusinessRuleException::make('unknown_country', "There is no holiday calendar for {$country} yet.");
        $out = [];
        foreach ($data['fixed'] ?? [] as [$m, $d, $name]) {
            $out[] = ['date' => sprintf('%04d-%02d-%02d', $year, $m, $d), 'name' => $name, 'kind' => 'fixed', 'is_estimate' => false];
        }
        $easter = self::easterSunday($year);
        foreach ($data['easter'] ?? [] as [$offset, $name]) {
            $out[] = ['date' => $easter->copy()->addDays($offset)->toDateString(), 'name' => $name, 'kind' => 'movable', 'is_estimate' => false];
        }
        foreach ($data['movable'][$year] ?? [] as [$date, $name, $estimate]) {
            $out[] = ['date' => $date, 'name' => $name, 'kind' => 'movable', 'is_estimate' => (bool) $estimate];
        }

        return $out;
    }

    /** Add a year's holidays that are missing (idempotent; days already in the table are kept as they are). @return int added */
    public function seed(string $country, int $year): int
    {
        $country = strtoupper($country);
        $added = 0;
        foreach (self::fromData($country, $year) as $h) {
            $row = SaccoPublicHoliday::query()->firstOrCreate(['country' => $country, 'date' => $h['date']],
                ['name' => $h['name'], 'kind' => $h['kind'], 'is_estimate' => $h['is_estimate']]);
            $added += $row->wasRecentlyCreated ? 1 : 0;
        }
        WorkingDays::forget();

        return $added;
    }

    /**
     * The platform admin confirms or moves a holiday (an Eid announced for another day). Moving: pass the old date
     * as $replaces, and it is removed in the same step.
     */
    public function set(string $country, string $date, string $name, ?string $replaces = null, ?int $byUserId = null): SaccoPublicHoliday
    {
        $country = strtoupper($country);
        $day = self::day($date);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw BusinessRuleException::make('invalid_holiday', 'Name the holiday (at most 120 characters).');
        }
        if ($replaces !== null) {
            SaccoPublicHoliday::query()->where('country', $country)->where('date', self::day($replaces))->delete();
        }
        $row = SaccoPublicHoliday::query()->updateOrCreate(['country' => $country, 'date' => $day],
            ['name' => $name, 'kind' => 'movable', 'is_estimate' => false, 'updated_by_id' => $byUserId]);
        WorkingDays::forget();

        return $row;
    }

    public function remove(string $country, string $date): bool
    {
        $gone = SaccoPublicHoliday::query()->where('country', strtoupper($country))->where('date', self::day($date))->delete() > 0;
        WorkingDays::forget();

        return $gone;
    }

    /** Easter Sunday in the Gregorian calendar (the anonymous algorithm; no calendar extension needed). */
    public static function easterSunday(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    private static function day(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw BusinessRuleException::make('invalid_date', "\"{$date}\" is not a date (YYYY-MM-DD).");
        }

        return $date;
    }
}

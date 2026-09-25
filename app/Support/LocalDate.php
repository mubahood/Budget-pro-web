<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Business dates written to DATE columns (sale_date, stock_records.date) in the shop's own
 * timezone. Timestamps stay UTC; a sale rung up at 01:30 in Kampala belongs to that Kampala day,
 * not to the previous UTC day. Every value returned is midnight (UTC) of the local calendar day,
 * so it formats to the right date wherever it is saved or compared.
 */
class LocalDate
{
    public static function timezone(int $companyId): string
    {
        return LocalTime::timezone(Company::withoutGlobalScopes()->find($companyId));
    }

    /** Today in the shop's timezone. */
    public static function today(int $companyId): Carbon
    {
        return Carbon::parse(now(self::timezone($companyId))->toDateString());
    }

    /** The shop's calendar day of a client timestamp in milliseconds. */
    public static function fromMs(int $companyId, int $ms): Carbon
    {
        return Carbon::parse(Carbon::createFromTimestampMs($ms)->setTimezone(self::timezone($companyId))->toDateString());
    }

    /**
     * A business date from user/client input: empty -> today; a bare date ("2026-09-25", or a
     * value at exactly midnight) is kept as that day; a moment in time is moved to the shop's timezone first.
     */
    public static function date(int $companyId, mixed $value = null): Carbon
    {
        if ($value === null || $value === '') {
            return self::today($companyId);
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return Carbon::parse(trim($value));
        }
        $at = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);
        if ($at->format('H:i:s') === '00:00:00') {
            return Carbon::parse($at->toDateString());
        }

        return Carbon::parse($at->copy()->setTimezone(self::timezone($companyId))->toDateString());
    }
}

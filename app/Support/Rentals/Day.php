<?php

namespace App\Support\Rentals;

use App\Exceptions\BusinessRuleException;

/**
 * A business date as a client sends it: a real day, written Y-m-d, in years a tenancy can have. Anything else is refused, never moved to another day (2026-02-31) or read as a day long gone
 * (0000-00-00, or a lease "from" year 1, which would raise bills for centuries). Nothing in the books is older than Handover::BEGINNING.
 */
final class Day
{
    public const FIRST = '2000-01-01';

    public const LAST = '2100-12-31';

    /** @return ?string the day, or null when none was given */
    public static function strict(mixed $value, string $field = 'the date'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            || $value < self::FIRST || $value > self::LAST) {
            throw BusinessRuleException::make('date_invalid', "Give {$field} as a date like 2026-10-31.");
        }

        return $value;
    }
}

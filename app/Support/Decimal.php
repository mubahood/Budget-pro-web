<?php

namespace App\Support;

/**
 * Exact money arithmetic on decimal strings (bcmath), for the code that must never use floats
 * (agent-command §7, DECISIONS G11/E17: money is DECIMAL(20,2) on the server). Every result is a
 * plain decimal string with $scale places ("1500.00"); inputs may be ints, numeric strings or null.
 */
final class Decimal
{
    public const SCALE = 2;

    /** A clean decimal string ("12" → "12.00", null/"" → "0.00"). Non-numeric input is refused, never read as 0. */
    public static function of(int|string|float|null $value, int $scale = self::SCALE): string
    {
        if ($value === null || $value === '') {
            return bcadd('0', '0', $scale);
        }
        if (is_float($value)) {
            // Only for values that came in as floats from old callers: printed with enough places, then cut exactly.
            $value = number_format($value, $scale + 2, '.', '');
        }
        $value = trim((string) $value);
        if (! is_numeric($value) || stripos($value, 'e') !== false) {
            throw new \InvalidArgumentException('Not a decimal amount: '.$value);
        }

        return self::round($value, $scale);
    }

    /** Whether $value reads as a decimal amount (what of() accepts). */
    public static function valid(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        return is_string($value) && is_numeric(trim($value)) && stripos($value, 'e') === false;
    }

    public static function add(int|string|null $a, int|string|null $b, int $scale = self::SCALE): string
    {
        return bcadd(self::of($a, $scale + 2), self::of($b, $scale + 2), $scale);
    }

    public static function sub(int|string|null $a, int|string|null $b, int $scale = self::SCALE): string
    {
        return bcsub(self::of($a, $scale + 2), self::of($b, $scale + 2), $scale);
    }

    /** a × b, rounded half away from zero to $scale places (unit price × quantity). */
    public static function mul(int|string|null $a, int|string|null $b, int $scale = self::SCALE): string
    {
        return self::round(bcmul(self::of($a, 6), self::of($b, 6), 12), $scale);
    }

    /** -1, 0 or 1. */
    public static function cmp(int|string|null $a, int|string|null $b): int
    {
        return bccomp(self::of($a, 6), self::of($b, 6), 6);
    }

    public static function max(int|string|null $a, int|string|null $b, int $scale = self::SCALE): string
    {
        return self::cmp($a, $b) >= 0 ? self::of($a, $scale) : self::of($b, $scale);
    }

    public static function neg(int|string|null $a, int $scale = self::SCALE): string
    {
        return bcsub('0', self::of($a, $scale), $scale);
    }

    public static function isZero(int|string|null $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    /** part / whole × 100, to 2 places; 0 when whole is 0. */
    public static function percent(int|string|null $part, int|string|null $whole): string
    {
        if (self::isZero($whole)) {
            return '0.00';
        }

        return self::round(bcdiv(bcmul(self::of($part, 6), '100', 6), self::of($whole, 6), 8), 2);
    }

    /** Half away from zero (what people expect on a receipt), exactly. */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';
        $value = str_starts_with($value, '-') ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);

        return $value === '-'.bcadd('0', '0', $scale) ? bcadd('0', '0', $scale) : $value;
    }
}

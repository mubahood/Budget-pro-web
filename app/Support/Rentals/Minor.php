<?php

namespace App\Support\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Support\Money;

/**
 * Estate Ledger money: integer minor units of the account's one currency (ADR 0010 §1). Parsing never goes through a
 * float, and every rounding is half up, once.
 */
final class Minor
{
    /** The largest amount Estate Ledger takes (10^14 minor units): an amount times a rate in basis points stays in 64 bits. */
    public const MAX = 100_000_000_000_000;

    public static function decimals(int $companyId): int
    {
        return Money::decimals($companyId);
    }

    /**
     * An amount typed by a person ("1,250,000", "1 250 000", "12.5") in major units → minor units. Refuses anything
     * that is not a plain positive number with at most the currency's decimals.
     */
    public static function parse(string|int|null $major, int $decimals): int
    {
        $s = str_replace([',', ' ', "\u{00A0}", '_'], '', trim((string) $major));
        if ($s === '' || ! preg_match('/^\d+(\.\d+)?$/', $s)) {
            throw BusinessRuleException::make('amount_invalid', 'Enter an amount in figures, for example 350,000.');
        }
        [$whole, $frac] = array_pad(explode('.', $s, 2), 2, '');
        if (strlen(rtrim($frac, '0')) > $decimals) {
            throw BusinessRuleException::make('amount_invalid', $decimals === 0 ? 'This currency has no cents: enter a whole amount.' : "Use at most {$decimals} decimal places.");
        }
        $frac = substr(str_pad($frac, $decimals, '0'), 0, $decimals);
        $minor = ltrim($whole.$frac, '0');
        if (strlen($minor) > 17 || (int) $minor > self::MAX) {
            throw BusinessRuleException::make('amount_invalid', 'That amount is too large.');
        }

        return (int) ($minor === '' ? '0' : $minor);
    }

    /** Minor units → a plain major string ("1250000", "12.50"), for formatting and exports. */
    public static function toMajor(int $minor, int $decimals): string
    {
        if ($decimals === 0) {
            return (string) $minor;
        }
        $neg = $minor < 0;
        $s = str_pad((string) abs($minor), $decimals + 1, '0', STR_PAD_LEFT);

        return ($neg ? '-' : '').substr($s, 0, -$decimals).'.'.substr($s, -$decimals);
    }

    /** For people: "UGX 1,250,000" (the kernel's formatter, given the major string). */
    public static function format(int $minor, int $companyId): string
    {
        return Money::format(self::toMajor($minor, self::decimals($companyId)), null, $companyId);
    }

    /** round(num / den), halves away from zero; den > 0. */
    public static function divRound(int $num, int $den): int
    {
        if ($den <= 0) {
            throw new \InvalidArgumentException('Divide by a positive number.');
        }
        $q = intdiv(abs($num), $den);
        if (abs($num) % $den * 2 >= $den) {
            $q++;
        }

        return $num < 0 ? -$q : $q;
    }

    /** $bp basis points of $amount (1000 bp = 10 %), rounded half up. */
    public static function percent(int $amount, int $bp): int
    {
        return self::divRound($amount * $bp, 10000);
    }

    /** $amount × $num / $den, rounded half up (pro rata by days, part payments). */
    public static function share(int $amount, int $num, int $den): int
    {
        if ($amount !== 0 && abs($num) > intdiv(PHP_INT_MAX, abs($amount))) {
            // Too big for 64 bits: the same half-up rounding in bcmath.
            $q = bcdiv(bcmul((string) $amount, (string) $num), (string) $den, 1);
            $sign = str_starts_with($q, '-') ? -1 : 1;

            return $sign * (int) bcadd(ltrim($q, '-'), '0.5', 0);
        }

        return self::divRound($amount * $num, $den);
    }

    /** A client's amount that may be zero (a deposit, a commission): a whole number, never rounded or read as zero. */
    public static function nonNegative(mixed $value, string $label = 'amount'): int
    {
        return $value === 0 || $value === '0' ? 0 : self::positive($value, $label);
    }

    /** Validate an amount given as minor units by a client (the API never takes decimals for money). */
    public static function positive(mixed $value, string $label = 'amount'): int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value <= 0 || $value > self::MAX) {
            throw BusinessRuleException::make('amount_invalid', "The {$label} must be a whole number above zero, in the currency's smallest unit.");
        }

        return $value;
    }
}

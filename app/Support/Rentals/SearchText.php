<?php

namespace App\Support\Rentals;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * How Estate Ledger cuts text for its search (the index in rental_search_keys, and what a person types). The index and the
 * query must cut text the same way, so both use this class and nothing else.
 *
 *  - words(): lower-case ASCII words. "Nakato-Ssali O'Brien" gives nakato, ssali, o, brien.
 *  - code(): an identifier without its punctuation, so "R-001450", "r 001450" and "R001450" are one key.
 *  - national(): a phone's national digits, so 0772 123456, 772123456 and +256772123456 are one number.
 *  - sound() and skeleton(): how a name sounds, because Ugandan names are written many ways: Nakato and Nakatto share a
 *    sound, Namuddu and Namudu too; Mohammed, Muhammad and Mohamed share a skeleton (their consonants).
 *  - amounts() and dates(): money and days the way an office writes them: 650k, 650,000, UGX 650000, 1.5m; 2 nov, 2/11/2026.
 */
final class SearchText
{
    /** Longest key kept. The term column holds 64 characters, with its two-letter prefix. */
    public const MAX_TERM = 60;

    /** @return list<string> */
    public static function words(?string $text): array
    {
        $ascii = Str::lower(Str::ascii((string) $text));
        $parts = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($parts));
    }

    public static function code(?string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii((string) $text)));
    }

    /** Only the digits of what was typed. */
    public static function digits(?string $text): string
    {
        return (string) preg_replace('/\D+/', '', (string) $text);
    }

    /**
     * The digits of a phone number with its 0 or country code taken off: what a person types after either is the same number.
     * A leading country code comes off only when digits remain to be a number (2567 stays 2567).
     */
    public static function national(?string $text): string
    {
        $digits = self::digits($text);
        foreach (['256', '254', '255', '250'] as $code) {
            if (str_starts_with($digits, $code) && strlen($digits) > strlen($code) + 5) {
                return substr($digits, strlen($code));
            }
        }

        return ltrim($digits, '0');
    }

    /** True when the query is a phone number or part of one: digits, spaces, + and dashes only, with three digits at least. */
    public static function looksLikePhone(string $query): bool
    {
        return preg_match('/^\+?[\d\s\-()]+$/', trim($query)) === 1 && strlen(self::digits($query)) >= 3;
    }

    /** True when the digits can only be a phone: a leading 0 or +, or a country code with a full number after it. */
    public static function surelyPhone(string $query): bool
    {
        $q = trim($query);
        if (! self::looksLikePhone($q)) {
            return false;
        }
        $digits = self::digits($q);

        return strlen($digits) >= 9 && ($q[0] === '+' || $q[0] === '0' || str_starts_with($digits, '256'));
    }

    /**
     * How a word sounds: the spellings of one name brought together. Letters written for one sound become one (ph f, dh d,
     * th t, kh k, q k, c k, y i, ou u, oo u, ee i), doubled letters become one, a final h goes. Applied the same way to the
     * index and the query, so it only has to be the same both ways, not right.
     */
    public static function sound(string $word): string
    {
        $w = (string) preg_replace('/[^a-z]/', '', Str::lower(Str::ascii($word)));
        if (strlen($w) < 3) {
            return '';
        }
        $w = strtr($w, ['ph' => 'f', 'dh' => 'd', 'th' => 't', 'kh' => 'k', 'gh' => 'g', 'ck' => 'k', 'ou' => 'u', 'oo' => 'u', 'ee' => 'i']);
        $w = strtr($w, ['q' => 'k', 'c' => 'k', 'y' => 'i']);
        $w = (string) preg_replace('/(.)\1+/', '$1', $w);
        $w = (string) preg_replace('/(?<=.)h$/', '', $w);

        return substr($w, 0, self::MAX_TERM);
    }

    /** A word's consonants after its first letter, from its sound. Four letters at least, or too many names would share it. */
    public static function skeleton(string $word): string
    {
        $sound = self::sound($word);
        if ($sound === '') {
            return '';
        }
        $rest = (string) preg_replace('/[aeiouw]/', '', substr($sound, 1));
        $skeleton = (string) preg_replace('/(.)\1+/', '$1', $sound[0].$rest);

        return strlen($skeleton) >= 4 ? substr($skeleton, 0, self::MAX_TERM) : '';
    }

    /**
     * Money in a query, the way an office writes it: "650,000", "UGX 650000", "650k", "1.5m". Written with a comma, a k or m,
     * or UGX, it is taken out of the query; plain digits (a receipt number, a code, a phone) stay in it and are also tried
     * as an amount when they could be one. Values are in whole currency units, as typed.
     *
     * @return array{0: list<int>, 1: string, 2: list<int>} amounts written as money, the query without them, plain numbers that could also be money
     */
    public static function amounts(string $query): array
    {
        $found = [];
        $plain = [];
        $rest = (string) preg_replace_callback('/(?<![\w.,\-\/])(ugx\s*)?(\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)\s*([km])?(?![\w,\-\/]|\.\d)/i', function ($m) use (&$found, &$plain) {
            $number = (float) str_replace(',', '', $m[2]);
            $unit = strtolower($m[3] ?? '');
            $value = (int) round($number * ($unit === 'k' ? 1000 : ($unit === 'm' ? 1000000 : 1)));
            $marked = $unit !== '' || trim($m[1]) !== '' || str_contains($m[2], ',');
            if ($value >= 100 && $value <= 99_999_999_999 && $marked) {
                $found[] = $value;
            } elseif ($value >= 100 && $value <= 99_999_999_999 && ctype_digit($m[2]) && $m[2][0] !== '0') {
                $plain[] = $value; // a leading 0 is a phone or a code, never money

                return $m[0];
            } else {
                return $m[0];
            }

            return ' ';
        }, $query);

        return [array_values(array_unique($found)), trim((string) preg_replace('/\s+/', ' ', $rest)), array_values(array_unique($plain))];
    }

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /**
     * Days in a query: "today", "yesterday", "2026-11-02", "2/11/2026" (day first, as written in Uganda), "2 Nov", "Nov 2nd 2026".
     * A day without a year is the latest one not after today. Taken out of the query.
     *
     * @return array{0: list<string>, 1: string} Y-m-d days, and the query without them
     */
    public static function dates(string $query, ?\DateTimeInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? now())->startOfDay();
        $found = [];
        $make = function (int $y, int $m, int $d) use (&$found): bool {
            if ($y < 100) {
                $y += 2000;
            }
            if (! checkdate($m, $d, $y) || $y < 2000 || $y > 2100) {
                return false;
            }
            $found[] = sprintf('%04d-%02d-%02d', $y, $m, $d);

            return true;
        };
        $noYear = function (int $m, int $d) use ($today, $make): bool {
            $y = (int) $today->year;
            if (checkdate($m, $d, $y) && CarbonImmutable::create($y, $m, $d)->gt($today)) {
                $y--;
            }

            return $make($y, $m, $d);
        };
        $month = fn (string $word): int => self::MONTHS[substr(strtolower($word), 0, 3)] ?? 0;
        $mon = '(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';
        $patterns = [
            '/\b(today|yesterday)\b/i' => function ($m) use ($today, $make) {
                $day = strtolower($m[1]) === 'today' ? $today : $today->subDay();

                return $make((int) $day->year, (int) $day->month, (int) $day->day);
            },
            '/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/' => fn ($m) => $make((int) $m[1], (int) $m[2], (int) $m[3]),
            '/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})\b/' => fn ($m) => $make((int) $m[3], (int) $m[2], (int) $m[1]),
            '/\b(\d{1,2})(?:st|nd|rd|th)?\s+'.$mon.'\b\.?(?:,?\s+(\d{4}))?\b/i' => fn ($m) => ($m[3] ?? '') !== '' ? $make((int) $m[3], $month($m[2]), (int) $m[1]) : $noYear($month($m[2]), (int) $m[1]),
            '/\b'.$mon.'\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?\b/i' => fn ($m) => ($m[3] ?? '') !== '' ? $make((int) $m[3], $month($m[1]), (int) $m[2]) : $noYear($month($m[1]), (int) $m[2]),
        ];
        foreach ($patterns as $pattern => $read) {
            $query = (string) preg_replace_callback($pattern, fn ($m) => $read($m) ? ' ' : $m[0], $query);
        }

        return [array_values(array_unique($found)), trim((string) preg_replace('/\s+/', ' ', $query))];
    }
}

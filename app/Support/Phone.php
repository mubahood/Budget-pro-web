<?php

namespace App\Support;

/**
 * E.164 phone normalisation for the launch countries (plan C10, H3): accepts
 * local (0772…), national without 0 (772…), and international (+256…/256…) forms.
 */
class Phone
{
    public const COUNTRIES = [
        'UG' => ['code' => '256', 'len' => 9],
        'KE' => ['code' => '254', 'len' => 9],
        'TZ' => ['code' => '255', 'len' => 9],
        'RW' => ['code' => '250', 'len' => 9],
    ];

    public static function e164(?string $raw, string $defaultCountry = 'UG'): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if ($digits === null || $digits === '') {
            return null;
        }
        if (str_starts_with(trim((string) $raw), '+') || strlen($digits) > 10) {
            foreach (self::COUNTRIES as $c) {
                if (str_starts_with($digits, $c['code']) && strlen($digits) === strlen($c['code']) + $c['len']) {
                    return '+'.$digits;
                }
            }
            if (str_starts_with(trim((string) $raw), '+') && strlen($digits) >= 8 && strlen($digits) <= 15) {
                return '+'.$digits; // other countries: trust the + form
            }
        }
        $c = self::COUNTRIES[strtoupper($defaultCountry)] ?? self::COUNTRIES['UG'];
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === $c['len']) {
            return '+'.$c['code'].$digits;
        }

        return null;
    }

    public static function countryFor(string $currency): string
    {
        return ['KES' => 'KE', 'TZS' => 'TZ', 'RWF' => 'RW'][strtoupper($currency)] ?? 'UG';
    }
}

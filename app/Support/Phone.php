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
        // Elsewhere (national number length without the leading 0). Other countries use the +code form.
        'AE' => ['code' => '971', 'len' => 9], 'AU' => ['code' => '61', 'len' => 9], 'BI' => ['code' => '257', 'len' => 8],
        'CA' => ['code' => '1', 'len' => 10], 'CD' => ['code' => '243', 'len' => 9], 'CI' => ['code' => '225', 'len' => 10],
        'CM' => ['code' => '237', 'len' => 9], 'EG' => ['code' => '20', 'len' => 10], 'ET' => ['code' => '251', 'len' => 9],
        'FR' => ['code' => '33', 'len' => 9], 'GB' => ['code' => '44', 'len' => 10], 'GH' => ['code' => '233', 'len' => 9],
        'IE' => ['code' => '353', 'len' => 9], 'IN' => ['code' => '91', 'len' => 10], 'MW' => ['code' => '265', 'len' => 9],
        'NG' => ['code' => '234', 'len' => 10], 'NL' => ['code' => '31', 'len' => 9], 'SA' => ['code' => '966', 'len' => 9],
        'SN' => ['code' => '221', 'len' => 9], 'SS' => ['code' => '211', 'len' => 9], 'US' => ['code' => '1', 'len' => 10],
        'ZA' => ['code' => '27', 'len' => 9], 'ZM' => ['code' => '260', 'len' => 9], 'ZW' => ['code' => '263', 'len' => 9],
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
        // A local number is read only for a country we know the format of; anywhere else it needs its +code.
        $c = self::COUNTRIES[strtoupper($defaultCountry)] ?? null;
        if ($c === null) {
            return null;
        }
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

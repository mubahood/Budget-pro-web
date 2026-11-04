<?php

namespace App\Support\Rentals;

use App\Support\Money;

/**
 * An amount in words for a receipt (docs/estate-ledger/04-architecture.md §10.4): "Two hundred and fifty thousand shillings
 * only", from integer minor units, in the account's currency. Pure apart from reading the currency.
 */
final class AmountWords
{
    private const CURRENCY = ['UGX' => 'shillings', 'KES' => 'shillings', 'TZS' => 'shillings', 'SOS' => 'shillings', 'USD' => 'dollars', 'EUR' => 'euros', 'GBP' => 'pounds',
        'RWF' => 'francs', 'BIF' => 'francs', 'CDF' => 'francs', 'ZAR' => 'rand', 'NGN' => 'naira', 'GHS' => 'cedis', 'ZMW' => 'kwacha', 'MWK' => 'kwacha'];

    public static function of(int $minor, int $companyId): string
    {
        $decimals = Minor::decimals($companyId);
        [$whole, $frac] = array_pad(explode('.', Minor::toMajor(abs($minor), $decimals), 2), 2, '');
        $code = strtoupper(Money::symbol($companyId));
        $words = ucfirst(self::spell((int) $whole)).' '.(self::CURRENCY[$code] ?? $code);
        if ($decimals > 0 && (int) $frac > 0) {
            $words .= ' and '.$frac.'/1'.str_repeat('0', $decimals);
        }

        return $words.' only';
    }

    private static function spell(int $n): string
    {
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen',
            'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        if ($n < 20) {
            return $ones[$n];
        }
        if ($n < 100) {
            return $tens[intdiv($n, 10)].($n % 10 ? '-'.$ones[$n % 10] : '');
        }
        if ($n < 1000) {
            return $ones[intdiv($n, 100)].' hundred'.($n % 100 ? ' and '.self::spell($n % 100) : '');
        }
        foreach ([1_000_000_000_000 => 'trillion', 1_000_000_000 => 'billion', 1_000_000 => 'million', 1000 => 'thousand'] as $size => $name) {
            if ($n >= $size) {
                $rest = $n % $size;

                return self::spell(intdiv($n, $size)).' '.$name.($rest ? ($rest < 100 ? ' and ' : ' ').self::spell($rest) : '');
            }
        }

        return (string) $n;
    }
}

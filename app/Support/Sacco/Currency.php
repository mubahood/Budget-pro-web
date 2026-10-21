<?php

namespace App\Support\Sacco;

use App\Exceptions\BusinessRuleException;

/**
 * ISO 4217 minor-unit exponents for Akiba amounts (ADR 0009 §2): how many decimals one major unit has. This is the
 * standard's exponent, not App\Support\Money::ZERO_DECIMAL, which is a display rule for shelf prices (it shows
 * KES and TZS without cents; the standard gives them 2). A code not listed here has the usual 2.
 */
final class Currency
{
    /** Codes whose minor unit is the major unit (exponent 0). */
    private const ZERO = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Codes with three decimals. */
    private const THREE = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /** An upper-case ISO 4217 alphabetic code, or the refusal `invalid_currency`. */
    public static function code(string $code): string
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw BusinessRuleException::make('invalid_currency', "\"{$code}\" is not a currency code. Use the three letters, like UGX.");
        }

        return $code;
    }

    public static function exponent(string $code): int
    {
        $code = self::code($code);

        return in_array($code, self::ZERO, true) ? 0 : (in_array($code, self::THREE, true) ? 3 : 2);
    }

    /** How many minor units make one major unit: 1 for UGX, 100 for KES and USD. */
    public static function factor(string $code): int
    {
        return 10 ** self::exponent($code);
    }
}

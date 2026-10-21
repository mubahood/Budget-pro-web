<?php

namespace App\Support\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Support\Money;

/**
 * Money in the SACCO books (ADR 0009 §2, AK-08, AK-09): whole minor units in a PHP int (a BIGINT column), with
 * an ISO 4217 currency. Immutable and never a float. Sums refuse a currency mismatch and integer overflow;
 * rates are applied with bcmath at scale 10 and rounded once (half-up by default); a total is split with the
 * largest-remainder method, so the parts always add up to the whole.
 *
 * Display only goes through App\Support\Money::format, with the major string, never a float sum.
 */
final class Amount implements \JsonSerializable
{
    private function __construct(public readonly int $minor, public readonly string $currency)
    {
    }

    public static function of(int $minor, string $currency): self
    {
        return new self($minor, Currency::code($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, Currency::code($currency));
    }

    /**
     * From a decimal written in major units ("1500", "12.50", "-3"), as a person or a form sends it. A float is
     * refused (it may already be wrong: 0.1 + 0.2), and so is a decimal finer than the currency's minor unit
     * ("12.5" UGX), an exponent, a thousands separator or anything not a number.
     */
    public static function fromMajor(string|int|float $decimal, string $currency): self
    {
        $currency = Currency::code($currency);
        if (is_float($decimal)) {
            throw BusinessRuleException::make('invalid_amount', 'Amounts are never floats. Send a string of digits, or whole minor units.');
        }
        $value = trim((string) $decimal);
        if (preg_match('/^-?\d+(\.(\d+))?$/', $value, $m) !== 1) {
            throw BusinessRuleException::make('invalid_amount', "\"{$value}\" is not an amount.");
        }
        $exponent = Currency::exponent($currency);
        if (strlen($m[2] ?? '') > $exponent) {
            throw BusinessRuleException::make('invalid_amount', $exponent === 0
                ? "{$currency} has no smaller unit: use whole amounts, not \"{$value}\"."
                : "{$currency} has {$exponent} decimals at most: \"{$value}\" has more.");
        }

        return new self(self::toInt(bcmul($value, (string) (10 ** $exponent), 0)), $currency);
    }

    public function plus(self $other): self
    {
        $this->sameCurrency($other);
        if (($other->minor > 0 && $this->minor > PHP_INT_MAX - $other->minor) || ($other->minor < 0 && $this->minor < PHP_INT_MIN - $other->minor)) {
            throw self::overflow();
        }

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->sameCurrency($other);
        if (($other->minor < 0 && $this->minor > PHP_INT_MAX + $other->minor) || ($other->minor > 0 && $this->minor < PHP_INT_MIN + $other->minor)) {
            throw self::overflow();
        }

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function negate(): self
    {
        if ($this->minor === PHP_INT_MIN) {
            throw self::overflow();
        }

        return new self(-$this->minor, $this->currency);
    }

    public function abs(): self
    {
        return $this->minor < 0 ? $this->negate() : $this;
    }

    public function compare(self $other): int
    {
        $this->sameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    /**
     * This amount times a rate or a ratio ("0.015", "5/12" is not accepted: give the decimal), computed exactly with
     * bcmath and rounded once to the minor unit (AK-09: half-up unless a cap needs DOWN).
     */
    public function times(Rate|string $ratio, Rounding $mode = Rounding::HALF_UP): self
    {
        $r = $ratio instanceof Rate ? $ratio->ratio() : trim($ratio);
        if (preg_match('/^-?\d+(\.\d+)?$/', $r) !== 1) {
            throw BusinessRuleException::make('invalid_rate', "\"{$r}\" is not a ratio.");
        }
        $decimals = strlen(substr(strrchr($r, '.') ?: '', 1));

        return new self(self::toInt(self::round(bcmul((string) $this->minor, $r, $decimals), $mode)), $this->currency);
    }

    /**
     * Split this amount in proportion to whole, non-negative weights (shares held, balances, days), with the
     * largest-remainder method: each part is the floor of its exact share, and the units left over go one each
     * to the largest remainders (earliest first on a tie). The parts sum exactly to this amount, a zero weight
     * gets nothing, and the keys of $weights are kept.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights
     * @return array<TKey, self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            throw BusinessRuleException::make('invalid_allocation', 'Nothing to share between.');
        }
        $total = '0';
        foreach ($weights as $w) {
            if (! is_int($w) || $w < 0) {
                throw BusinessRuleException::make('invalid_allocation', 'Weights are whole numbers, zero or more.');
            }
            $total = bcadd($total, (string) $w, 0);
        }
        if ($total === '0') {
            throw BusinessRuleException::make('invalid_allocation', 'The weights add up to zero.');
        }
        $whole = ltrim((string) $this->minor, '-');
        $parts = [];
        $remainders = [];
        $given = '0';
        foreach ($weights as $key => $w) {
            $product = bcmul($whole, (string) $w, 0);
            $parts[$key] = bcdiv($product, $total, 0);
            $remainders[$key] = bcmod($product, $total, 0);
            $given = bcadd($given, $parts[$key], 0);
        }
        $left = (int) bcsub($whole, $given, 0);
        $order = array_keys($weights);
        $position = array_flip($order);
        usort($order, fn ($a, $b) => bccomp($remainders[$b], $remainders[$a], 0) ?: $position[$a] <=> $position[$b]);
        foreach (array_slice($order, 0, $left) as $key) {
            $parts[$key] = bcadd($parts[$key], '1', 0);
        }
        $sign = $this->minor < 0 ? -1 : 1;

        return array_map(fn (string $p) => new self($sign * (int) $p, $this->currency), $parts);
    }

    /** "1500" (UGX), "12.50" (KES), "-0.05" (USD): the major units, exact. */
    public function toMajorString(): string
    {
        $exponent = Currency::exponent($this->currency);
        if ($exponent === 0) {
            return (string) $this->minor;
        }
        $digits = str_pad(ltrim((string) $this->minor, '-'), $exponent + 1, '0', STR_PAD_LEFT);

        return ($this->minor < 0 ? '-' : '').substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    /** For display only, through Money::format (the company's symbol), with this currency's decimals. */
    public function format(?int $companyId = null): string
    {
        return Money::format($this->toMajorString(), Currency::exponent($this->currency), $companyId);
    }

    /** @return array{minor: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency];
    }

    private function sameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw BusinessRuleException::make('currency_mismatch', "Cannot combine {$this->currency} and {$other->currency}.");
        }
    }

    /** A bcmath integer string as a PHP int, refused past the BIGINT range. */
    private static function toInt(string $integer): int
    {
        if (bccomp($integer, (string) PHP_INT_MAX, 0) > 0 || bccomp($integer, (string) PHP_INT_MIN, 0) < 0) {
            throw self::overflow();
        }

        return (int) $integer;
    }

    /** One rounding of an exact decimal string to a whole number. */
    private static function round(string $x, Rounding $mode): string
    {
        $negative = str_starts_with($x, '-');
        $abs = ltrim($x, '-');
        $whole = bcadd($abs, '0', 0); // truncates
        $hasFraction = bccomp($abs, $whole, 20) !== 0;
        $rounded = match ($mode) {
            Rounding::DOWN => $whole,
            Rounding::UP => $hasFraction ? bcadd($whole, '1', 0) : $whole,
            Rounding::HALF_UP => bcadd(bcadd($abs, '0.5', 20), '0', 0),
        };

        return $negative && $rounded !== '0' ? '-'.$rounded : $rounded;
    }

    private static function overflow(): BusinessRuleException
    {
        return BusinessRuleException::make('amount_overflow', 'This amount is beyond what the books can hold.');
    }
}

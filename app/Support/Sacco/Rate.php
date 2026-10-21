<?php

namespace App\Support\Sacco;

use App\Exceptions\BusinessRuleException;

/**
 * A rate in whole basis points (ADR 0009 §2, AK-08): 150 = 1.50 %. Stored as `*_bp INT UNSIGNED`, so a rate is
 * exact and never a float. A rate is per its own period (a loan product says month or year); `perPeriod()` turns
 * a yearly rate into the ratio for one schedule period, at bcmath scale 10, for Amount::times to round once.
 */
final class Rate implements \JsonSerializable
{
    /** Ratios are carried at this bcmath scale and rounded once, in Amount::times. */
    public const SCALE = 10;

    /** Periods in a year for each repayment or posting frequency. */
    public const PERIODS_PER_YEAR = ['daily' => 365, 'weekly' => 52, 'fortnightly' => 26, 'monthly' => 12, 'quarterly' => 4, 'annually' => 1];

    private function __construct(public readonly int $bp)
    {
    }

    public static function bp(int $bp): self
    {
        if ($bp < 0) {
            throw BusinessRuleException::make('invalid_rate', 'A rate cannot be negative.');
        }

        return new self($bp);
    }

    /** From a percentage typed by a person, "1.5" or "18": at most two decimals (whole basis points). */
    public static function percent(string $percent): self
    {
        $percent = trim($percent);
        if (preg_match('/^\d+(\.\d{1,2})?$/', $percent) !== 1) {
            throw BusinessRuleException::make('invalid_rate', "\"{$percent}\" is not a rate. Use a percentage with at most two decimals, like 1.5.");
        }

        return new self((int) bcmul($percent, '100', 0));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** The rate as a ratio: 150 bp → "0.0150000000". */
    public function ratio(): string
    {
        return bcdiv((string) $this->bp, '10000', self::SCALE);
    }

    /** A yearly rate's share for one month: 1800 bp → "0.0150000000". */
    public function monthly(): string
    {
        return $this->perPeriod('monthly');
    }

    /** A yearly rate's share for one period of a frequency (AK-39: the yearly rate ÷ 12, 26 or 52). */
    public function perPeriod(string $frequency): string
    {
        $n = self::PERIODS_PER_YEAR[$frequency] ?? throw BusinessRuleException::make('invalid_frequency', "Unknown frequency \"{$frequency}\".");

        return bcdiv((string) $this->bp, (string) (10000 * $n), self::SCALE);
    }

    /**
     * Half of this rate, rounded down to a whole basis point: the legal ceiling of a penalty rate (SI 108 reg. 4(5),
     * AK-28), so a cap is never rounded above the law.
     */
    public function halfOf(): self
    {
        return new self(intdiv($this->bp, 2));
    }

    public function isZero(): bool
    {
        return $this->bp === 0;
    }

    public function compare(self $other): int
    {
        return $this->bp <=> $other->bp;
    }

    /** "1.5%", "18%", "0.25%". */
    public function percentString(): string
    {
        $s = bcdiv((string) $this->bp, '100', 2);

        return rtrim(rtrim($s, '0'), '.').'%';
    }

    /** @return array{bp: int} */
    public function jsonSerialize(): array
    {
        return ['bp' => $this->bp];
    }
}

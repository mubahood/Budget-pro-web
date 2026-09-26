<?php

namespace App\Support;

/**
 * Shelf prices a shop would actually write (SUPERMARKET_PLAN.md B5). nice() / niceCents() are the
 * template packs' rounding (ProductTemplateSeeder delegates here, same results); suggest() offers the
 * next "nice" prices when someone types a new price, and fromCost() prices from cost + margin %.
 */
final class NicePrice
{
    /** Currencies priced with cents on the shelf ($1.25, €0.45). */
    public const CENTS = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'SAR', 'ZAR', 'GHS', 'ZMW', 'EGP'];

    public static function usesCents(?string $currency): bool
    {
        return in_array(strtoupper((string) $currency), self::CENTS, true);
    }

    /** 0.274 → 0.25, 1.37 → 1.40, 12.34 → 12.30, 143 → 145: a shelf price in a currency with cents. */
    public static function niceCents(float $v): float
    {
        if ($v <= 0) {
            return 0;
        }
        $step = self::centsStep($v);

        return round(max($step, round($v / $step) * $step), 2);
    }

    /** A price a shop would write on a shelf: 178.6 → 180, 3,493 → 3,500, 38 → 40. */
    public static function nice(float $v): int
    {
        if ($v <= 0) {
            return 0;
        }
        $step = self::step($v);

        return (int) max($step, round($v / $step) * $step);
    }

    public static function step(float $v): int
    {
        return match (true) {
            $v < 10 => 1,
            $v < 100 => 5,
            $v < 1000 => 10,
            $v < 10000 => 50,
            $v < 100000 => 500,
            default => 1000,
        };
    }

    private static function centsStep(float $v): float
    {
        return match (true) {
            $v < 1 => 0.05,
            $v < 20 => 0.1,
            $v < 100 => 0.5,
            $v < 1000 => 5,
            default => 10,
        };
    }

    /** nice() or niceCents(), by currency. */
    public static function round(float $v, ?string $currency): float
    {
        return self::usesCents($currency) ? self::niceCents($v) : (float) self::nice($v);
    }

    /**
     * Nice prices at or just above a typed price, cheapest first, without the price itself:
     *   UGX 3,420 → 3,450 (next step), 3,500 (nearest 50/500 up), 3,950 (x,950);
     *   USD 4.37  → 4.40, 4.50, 4.99.
     *
     * @return list<float>
     */
    public static function suggest(float $v, ?string $currency): array
    {
        if ($v <= 0) {
            return [];
        }
        $cents = self::usesCents($currency);
        $out = [];
        if ($cents) {
            $step = self::centsStep($v);
            $out[] = round(ceil(round($v / $step, 6)) * $step, 2);            // next step up
            $out[] = round(ceil(round($v * 2, 6)) / 2, 2);                   // next .50 / .00
            $out[] = $v < 1 ? round(ceil(round($v * 10, 6)) / 10 - 0.01, 2) : round(ceil(round($v + 0.01, 6)) - 0.01, 2); // x.99
        } else {
            $step = self::step($v);
            $out[] = (float) (ceil(round($v / $step, 6)) * $step);           // next step up
            $out[] = (float) (ceil(round($v / 50, 6)) * 50);                // nearest 50 up
            if ($v >= 1000) {
                $out[] = (float) (ceil(round(($v + 50) / 1000, 6)) * 1000 - 50); // x,950
            } elseif ($v >= 100) {
                $out[] = (float) (ceil(round(($v + 1) / 100, 6)) * 100 - 1); // x99
            }
        }
        $out = array_values(array_unique(array_filter($out, fn ($p) => $p >= $v && abs($p - $v) > 0.001), SORT_REGULAR));
        sort($out);

        return array_values(array_map('floatval', $out));
    }

    /** A selling price from a cost and a margin on the selling price (25% margin: cost 750 → 1,000), rounded nice. */
    public static function fromCost(float $cost, float $marginPct, ?string $currency): float
    {
        if ($cost <= 0 || $marginPct < 0 || $marginPct >= 100) {
            return 0;
        }
        $raw = $cost / (1 - $marginPct / 100);
        $nice = self::round($raw, $currency);

        // Rounding must not eat the margin: take the next nice price up instead.
        return $nice >= $raw ? $nice : (self::suggest($raw, $currency)[0] ?? $nice);
    }
}

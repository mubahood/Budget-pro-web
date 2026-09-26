<?php

namespace App\Support;

use App\Models\Company;

/**
 * Cash rounding (SUPERMARKET_PLAN.md A7): with `fast_tender` on and a `cash_rounding` step set
 * (e.g. 50 UGX, 0.05 USD), a sale paid in cash only is rounded to the nearest step. The difference is
 * the sale's `rounding_amount` (a "Rounding" line), part of its total, so the cash counted, the
 * payments and the sales reports all agree. Anything else (no step, feature off, card, credit) is 0.
 */
class CashRounding
{
    /** The step, or 0 when this shop does not round cash. */
    public static function step(?Company $company): float
    {
        if (! StoreFeatures::enabled($company, 'fast_tender')) {
            return 0.0;
        }
        $step = (float) StoreFeatures::setting($company, 'cash_rounding');

        return $step > 0 ? $step : 0.0;
    }

    /** What to add to $total (negative rounds down) so it is a whole number of steps, to the nearest. */
    public static function amount(?Company $company, float $total): float
    {
        $step = self::step($company);
        if ($step <= 0 || $total <= 0) {
            return 0.0;
        }
        $rounded = round(round($total / $step) * $step, 2);

        return round($rounded - $total, 2);
    }

    /** Rounding applies to a sale paid now, wholly in cash. @param list<array{method?: string, amount?: mixed}> $payments */
    public static function appliesTo(array $payments): bool
    {
        $paid = array_filter($payments, fn ($p) => (float) ($p['amount'] ?? 0) > 0);
        if ($paid === []) {
            return false;
        }
        foreach ($paid as $p) {
            if (\App\Models\Payment::normalizeMethod((string) ($p['method'] ?? 'cash')) !== 'cash') {
                return false;
            }
        }

        return true;
    }
}

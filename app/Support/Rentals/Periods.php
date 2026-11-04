<?php

namespace App\Support\Rentals;

use Illuminate\Support\Carbon;

/**
 * Billing periods of a lease (docs/estate-ledger/04-architecture.md §5.1). Pure: the same lease gives the same periods
 * every time, so billing can be re-run without counting a period twice.
 *
 * - Anchor `start`: period k starts at start + k × cycle months, always counted from the start date with no
 *   overflow (31 Jan → 28 Feb → 31 Mar), and ends the day before the next one starts.
 * - Anchor `first`: a start on any day but the 1st gives a part period to the end of that month (`part` with its
 *   days and the month's days, for pro rata rent); periods then start on the 1st, every `cycle` months.
 */
final class Periods
{
    /**
     * Periods that start on or after $from and on or before $until, in order.
     *
     * @return list<array{start: Carbon, end: Carbon, part: bool, days: int, month_days: int}>
     */
    public static function between(Carbon $leaseStart, int $cycle, string $anchor, Carbon $from, Carbon $until, ?Carbon $stopBefore = null): array
    {
        $cycle = max(1, $cycle);
        $leaseStart = $leaseStart->copy()->startOfDay();
        $out = [];
        $base = $leaseStart->copy();
        if ($anchor === 'first' && $leaseStart->day !== 1) {
            $end = $leaseStart->copy()->endOfMonth()->startOfDay();
            $part = ['start' => $leaseStart->copy(), 'end' => $end, 'part' => true, 'days' => self::days($leaseStart, $end) + 1, 'month_days' => $leaseStart->daysInMonth];
            if (self::keep($part['start'], $from, $until, $stopBefore)) {
                $out[] = $part;
            }
            $base = $leaseStart->copy()->startOfMonth()->addMonthNoOverflow();
        }
        for ($k = 0; $k < 2400; $k++) {
            $start = $base->copy()->addMonthsNoOverflow($k * $cycle);
            if ($start->gt($until) || ($stopBefore !== null && $start->gte($stopBefore))) {
                break;
            }
            $end = $base->copy()->addMonthsNoOverflow(($k + 1) * $cycle)->subDay();
            if ($start->gte($from)) {
                $out[] = ['start' => $start, 'end' => $end, 'part' => false, 'days' => self::days($start, $end) + 1, 'month_days' => $start->daysInMonth];
            }
        }

        return $out;
    }

    /** The period that contains $day (null before the lease starts). Periods are contiguous: it is the last one to start. */
    public static function containing(Carbon $leaseStart, int $cycle, string $anchor, Carbon $day): ?array
    {
        if ($day->lt($leaseStart->copy()->startOfDay())) {
            return null;
        }
        $periods = self::between($leaseStart, $cycle, $anchor, $leaseStart, $day->copy()->startOfDay());
        $last = end($periods);

        return $last !== false && $last['end']->gte($day->copy()->startOfDay()) ? $last : null;
    }

    /**
     * Whole days from $a to $b (negative when $b is earlier). The kernel runs Carbon 2 and the portal Carbon 3, whose
     * diffInDays() differ in sign and type, so Estate Ledger counts days itself.
     */
    public static function days(Carbon|\DateTimeInterface $a, Carbon|\DateTimeInterface $b): int
    {
        $x = Carbon::parse($a->format('Y-m-d'), 'UTC');
        $y = Carbon::parse($b->format('Y-m-d'), 'UTC');

        return intdiv($y->getTimestamp() - $x->getTimestamp(), 86400);
    }

    private static function keep(Carbon $start, Carbon $from, Carbon $until, ?Carbon $stopBefore): bool
    {
        return $start->gte($from) && $start->lte($until) && ($stopBefore === null || $start->lt($stopBefore));
    }
}

<?php

namespace App\Services\Sacco\YearEnd;

use App\Services\Sacco\Loans\LoanBooks;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A SACCO's financial years (AK-41, Tier 4 s.45: 1 January to 31 December by default; `fiscal_year_start_month`
 * moves the start). A year is named by the calendar year it ends in.
 */
final class FiscalCalendar
{
    public static function startMonth(int $companyId): int
    {
        return (int) (LoanBooks::settings($companyId)->fiscal_year_start_month ?: 1);
    }

    /** @return array{0: Carbon, 1: Carbon} the first and last day of financial year $year */
    public static function bounds(int $companyId, int $year): array
    {
        $start = self::startMonth($companyId);
        $end = Carbon::create($start === 1 ? $year : $year, $start === 1 ? 12 : $start - 1, 1)->endOfMonth()->startOfDay();

        return [$end->copy()->addDay()->subYearNoOverflow()->startOfDay(), $end];
    }

    /** The financial year a day falls in. */
    public static function yearOf(int $companyId, CarbonInterface|string $day): int
    {
        $d = Carbon::parse($day);
        $start = self::startMonth($companyId);

        return $start === 1 || $d->month < $start ? (int) $d->year : (int) $d->year + 1;
    }

    /** The first day of the financial year a day falls in. */
    public static function startOf(int $companyId, CarbonInterface|string $day): Carbon
    {
        return self::bounds($companyId, self::yearOf($companyId, $day))[0];
    }
}

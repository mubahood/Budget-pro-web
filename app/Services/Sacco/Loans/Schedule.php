<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Support\Sacco\Amount;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Loan schedule maths, pure (docs/akiba/04-architecture.md §5.3, AK-07, AK-09, AK-39). No database, no clock.
 *
 * - Reducing balance, equal instalments (annuity): A = P·r·(1+r)^m / ((1+r)^m − 1), rounded half-up once; each line's
 *   interest is the opening principal × r, rounded half-up once (the same figure the accrual posts on a loan paid to
 *   schedule); principal = A − interest; the last line takes whatever principal is left, so Σ principal = P exactly.
 * - Reducing balance, equal principal: P ÷ m rounded down on every line but the last, which absorbs the rest.
 * - Flat (group mode only, AK-07): every line's interest is the original principal × r, rounded half-up once (the
 *   same rounding as a reducing line, so reducing balance never costs more than flat, to the unit).
 * - Principal grace: the first g lines pay interest only; the remaining m = n − g lines amortise.
 *
 * r is the yearly rate's share of one period (LoanTerms::periodicRatio, scale 20). Interest for early settlement is
 * to the day (outstanding × yearly rate × days ÷ 365, SI 51 reg. 39, SI 108 reg. 12) and carries no fee.
 */
final class Schedule
{
    public const SCALE = 20;

    /** @return list<ScheduleLine> */
    public static function build(int $principalMinor, LoanTerms $terms, CarbonInterface|string $firstDue, string $currency = 'UGX'): array
    {
        if ($principalMinor < 1) {
            throw BusinessRuleException::make('invalid_amount', 'A loan is for more than nothing.');
        }
        $n = $terms->term;
        $g = $terms->principalGracePeriods;
        $m = $n - $g;
        if ($principalMinor < $m) {
            throw BusinessRuleException::make('invalid_amount', 'The loan is too small to repay over that many instalments.');
        }
        $r = $terms->periodicRatio();
        $dates = self::dueDates($firstDue, $terms->frequency, $n);
        $money = fn (int $minor) => Amount::of($minor, $currency);
        $lines = [];
        $opening = $principalMinor;

        if ($terms->method === 'flat') {
            $interest = $money($principalMinor)->times($r)->minor;
            $principalEach = intdiv($principalMinor, $m);
            for ($i = 1; $i <= $n; $i++) {
                $principal = $i <= $g ? 0 : ($i === $n ? $opening : $principalEach);
                $lines[] = new ScheduleLine($i, $dates[$i - 1], $opening, $principal, $interest);
                $opening -= $principal;
            }

            return $lines;
        }

        for ($i = 1; $i <= $g; $i++) {
            $lines[] = new ScheduleLine($i, $dates[$i - 1], $opening, 0, $money($opening)->times($r)->minor);
        }
        $instalment = $terms->method === 'reducing_annuity' && bccomp($r, '0', self::SCALE) === 1 ? self::annuity($opening, $r, $m, $currency) : null;
        $principalEach = intdiv($opening, $m);
        for ($j = 1; $j <= $m; $j++) {
            $i = $g + $j;
            $interest = $money($opening)->times($r)->minor;
            if ($j === $m) {
                $principal = $opening;
            } elseif ($instalment !== null) {
                $principal = max(0, min($opening, $instalment - $interest));
            } else {
                $principal = $principalEach;
            }
            $lines[] = new ScheduleLine($i, $dates[$i - 1], $opening, $principal, $interest);
            $opening -= $principal;
        }

        return $lines;
    }

    /** The equal instalment for $principal over $periods at ratio $r, rounded half-up once. */
    public static function annuity(int $principal, string $r, int $periods, string $currency = 'UGX'): int
    {
        $s = self::SCALE + 10;
        $growth = bcpow(bcadd('1', $r, $s), (string) $periods, $s);
        $factor = bcdiv(bcmul($r, $growth, $s), bcsub($growth, '1', $s), self::SCALE);

        return Amount::of($principal, $currency)->times($factor)->minor;
    }

    /**
     * The due dates: the first, then one period apart. Monthly dates keep the first date's day of month where the
     * month has it (31 January, 28 February, 31 March).
     *
     * @return list<string>
     */
    public static function dueDates(CarbonInterface|string $firstDue, string $frequency, int $count): array
    {
        $first = Carbon::parse($firstDue)->startOfDay();
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = match ($frequency) {
                'weekly' => $first->copy()->addDays(7 * $i),
                'fortnightly' => $first->copy()->addDays(14 * $i),
                'monthly' => $first->copy()->addMonthsNoOverflow($i),
                default => throw BusinessRuleException::make('frequency_not_available', "\"{$frequency}\" is not a repayment frequency."),
            };
            $out[$i] = $out[$i]->toDateString();
        }

        return $out;
    }

    /** The first due date one period after the money is paid out (the officer may choose another, P2.9). */
    public static function firstDueAfter(CarbonInterface|string $disbursedOn, string $frequency): Carbon
    {
        $d = Carbon::parse($disbursedOn)->startOfDay();

        return match ($frequency) {
            'weekly' => $d->addDays(7),
            'fortnightly' => $d->addDays(14),
            'monthly' => $d->addMonthsNoOverflow(1),
            default => throw BusinessRuleException::make('frequency_not_available', "\"{$frequency}\" is not a repayment frequency."),
        };
    }

    /** @param  list<ScheduleLine>  $lines @return array{principal: int, interest: int, fees: int, total: int} */
    public static function totals(array $lines): array
    {
        $p = $i = $f = 0;
        foreach ($lines as $l) {
            $p += $l->principal;
            $i += $l->interest;
            $f += $l->fee;
        }

        return ['principal' => $p, 'interest' => $i, 'fees' => $f, 'total' => $p + $i + $f];
    }

    /** The charges taken when the money is paid out: the processing fee and insurance (counted in the cost of credit). @return array{fee: int, insurance: int} */
    public static function upfrontCharges(int $principalMinor, LoanTerms $terms, string $currency = 'UGX'): array
    {
        $fee = $terms->processingFeeMinor + Amount::of($principalMinor, $currency)->times(bcdiv((string) $terms->processingFeeBp, '10000', 4))->minor;
        $insurance = Amount::of($principalMinor, $currency)->times(bcdiv((string) $terms->insuranceBp, '10000', 4))->minor;

        return ['fee' => $fee, 'insurance' => $insurance];
    }

    /**
     * The total cost of credit as a yearly share of the principal, in basis points, rounded up (so a cap is never met
     * by rounding): (interest + fees + insurance) × 365 ÷ (principal × days). SI 108 reg. 4(2): at most 10000 (100 %).
     */
    public static function costPerYearBp(int $principalMinor, int $costMinor, int $termDays): int
    {
        if ($principalMinor < 1 || $termDays < 1) {
            return 0;
        }
        $numerator = bcmul((string) $costMinor, (string) (10000 * 365), 0);
        $denominator = bcmul((string) $principalMinor, (string) $termDays, 0);
        $whole = bcdiv($numerator, $denominator, 0);

        return (int) (bccomp(bcmod($numerator, $denominator, 0), '0', 0) === 1 ? bcadd($whole, '1', 0) : $whole);
    }

    /** Interest to the day on early settlement: outstanding × yearly rate × days ÷ 365, half-up once; never a fee. */
    public static function interestToDay(int $outstandingMinor, LoanTerms $terms, CarbonInterface|string $from, CarbonInterface|string $to, string $currency = 'UGX'): int
    {
        $days = (int) Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay(), false);
        if ($days <= 0 || $outstandingMinor <= 0) {
            return 0;
        }
        $ratio = bcdiv(bcmul((string) $terms->annualRateBp(), (string) $days, 0), (string) (10000 * 365), self::SCALE);

        return Amount::of($outstandingMinor, $currency)->times($ratio)->minor;
    }

    /**
     * A settlement quote from a loan's position (P2.10): everything owed today plus interest to the day for the running
     * period, and nothing else (no early-settlement charge, SI 108 reg. 12).
     *
     * @param  array{principal: int, interest: int, interest_suspense: int, penalty: int, penalty_suspense: int, fees: int}  $owed
     * @return array{principal_minor: int, interest_minor: int, interest_to_day_minor: int, penalty_minor: int, fees_minor: int, early_settlement_fee_minor: int, total_minor: int}
     */
    public static function settlementQuote(array $owed, LoanTerms $terms, CarbonInterface|string $periodStart, CarbonInterface|string $on, string $currency = 'UGX'): array
    {
        $toDay = self::interestToDay($owed['principal'], $terms, $periodStart, $on, $currency);
        $interest = $owed['interest'] + $owed['interest_suspense'];
        $penalty = $owed['penalty'] + $owed['penalty_suspense'];

        return ['principal_minor' => $owed['principal'], 'interest_minor' => $interest, 'interest_to_day_minor' => $toDay, 'penalty_minor' => $penalty,
            'fees_minor' => $owed['fees'], 'early_settlement_fee_minor' => 0, 'total_minor' => $owed['principal'] + $interest + $toDay + $penalty + $owed['fees']];
    }
}

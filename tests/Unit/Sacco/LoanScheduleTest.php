<?php

namespace Tests\Unit\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Services\Sacco\Loans\LoanTerms;
use App\Services\Sacco\Loans\Schedule;
use App\Services\Sacco\Loans\ScheduleLine;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Akiba Ledger P2.2 (04 §5.3, AK-07, AK-09, AK-39): the schedule maths, pure. Worked figures a treasurer can check by
 * hand, then 2 seeds × 400 random loans: Σ principal = the loan, every line's interest is its opening principal × the
 * periodic rate rounded once, annuity instalments are equal and the last line only absorbs rounding, reducing
 * balance never costs more than flat, and early settlement carries interest to the day and no fee.
 */
class LoanScheduleTest extends TestCase
{
    private function terms(string $method = 'reducing_annuity', int $rateBp = 200, string $frequency = 'monthly', int $term = 12, int $grace = 0, string $period = 'month'): LoanTerms
    {
        return new LoanTerms($method, $rateBp, $period, $frequency, $term, $grace);
    }

    private function refused(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected {$code}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    public function test_worked_annuity_equal_principal_and_flat(): void
    {
        // 1,000,000 at 2 % a month over 12 months: A = 94,559.60 → 94,560.
        $lines = Schedule::build(1_000_000, $this->terms(), '2026-11-15');
        $this->assertCount(12, $lines);
        $this->assertSame(94_560, Schedule::annuity(1_000_000, '0.02', 12));
        $this->assertSame([1, '2026-11-15', 1_000_000, 74_560, 20_000], [$lines[0]->no, $lines[0]->dueOn, $lines[0]->openingPrincipal, $lines[0]->principal, $lines[0]->interest]);
        $this->assertSame(925_440, $lines[1]->openingPrincipal);
        $this->assertSame(18_509, $lines[1]->interest, '925,440 × 2 % = 18,508.8 → 18,509');
        foreach (array_slice($lines, 0, 11) as $l) {
            $this->assertSame(94_560, $l->instalment());
        }
        $t = Schedule::totals($lines);
        $this->assertSame(1_000_000, $t['principal']);
        $this->assertSame('2027-10-15', $lines[11]->dueOn);
        $this->assertLessThanOrEqual(12, abs($lines[11]->instalment() - 94_560), 'the last line only absorbs rounding');

        $ep = Schedule::build(1_200_000, $this->terms('reducing_equal_principal'), '2026-11-30');
        $this->assertSame([100_000, 24_000], [$ep[0]->principal, $ep[0]->interest]);
        $this->assertSame([100_000, 2_000], [$ep[11]->principal, $ep[11]->interest]);
        $this->assertSame(156_000, Schedule::totals($ep)['interest'], '2 % × (1.2 m + 1.1 m + … + 0.1 m)');
        $this->assertSame(['2026-11-30', '2026-12-30', '2027-01-30', '2027-02-28'], array_map(fn (ScheduleLine $l) => $l->dueOn, array_slice($ep, 0, 4)));

        // A VSLA's 10 % a month service charge, flat, over 3 months.
        $flat = Schedule::build(100_000, $this->terms('flat', 1000, 'monthly', 3), '2026-11-01');
        $this->assertSame([[33_333, 10_000], [33_333, 10_000], [33_334, 10_000]], array_map(fn ($l) => [$l->principal, $l->interest], $flat));
        $this->assertLessThan(Schedule::totals($flat)['interest'], Schedule::totals(Schedule::build(100_000, $this->terms('reducing_annuity', 1000, 'monthly', 3), '2026-11-01'))['interest']);
    }

    public function test_grace_frequencies_and_month_ends(): void
    {
        $lines = Schedule::build(600_000, $this->terms('reducing_equal_principal', 150, 'monthly', 6, 2), '2027-01-31');
        $this->assertSame([0, 0, 150_000, 150_000, 150_000, 150_000], array_map(fn ($l) => $l->principal, $lines));
        $this->assertSame([9_000, 9_000, 9_000, 6_750, 4_500, 2_250], array_map(fn ($l) => $l->interest, $lines));
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], array_map(fn ($l) => $l->dueOn, array_slice($lines, 0, 4)));

        $weekly = $this->terms('reducing_annuity', 150, 'weekly', 8);
        $this->assertSame('0.00346153846153846153', $weekly->periodicRatio(), '18 % a year ÷ 52');
        $this->assertSame(['2026-11-02', '2026-11-09', '2026-11-16'], array_slice(Schedule::dueDates('2026-11-02', 'weekly', 3), 0, 3));
        $this->assertSame(['2026-11-02', '2026-11-16'], Schedule::dueDates('2026-11-02', 'fortnightly', 2));
        $this->assertSame('2026-11-30', Schedule::firstDueAfter('2026-10-31', 'monthly')->toDateString());
        $this->assertSame(1800, $weekly->annualRateBp());
        $this->assertSame(150, (new LoanTerms('reducing_annuity', 1800, 'year', 'monthly', 12))->monthlyRateBp());
        $this->assertSame('0.01500000000000000000', (new LoanTerms('reducing_annuity', 1800, 'year', 'monthly', 12))->periodicRatio());
    }

    public function test_settlement_has_interest_to_the_day_and_no_fee_and_the_cost_ceiling(): void
    {
        $t = $this->terms('reducing_annuity', 200);
        $this->assertSame(9_863, Schedule::interestToDay(1_000_000, $t, '2026-11-01', '2026-11-16'), '1 m × 24 % × 15 ÷ 365 = 9,863.01');
        $this->assertSame(0, Schedule::interestToDay(1_000_000, $t, '2026-11-16', '2026-11-16'));
        $q = Schedule::settlementQuote(['principal' => 500_000, 'interest' => 10_000, 'interest_suspense' => 2_000, 'penalty' => 1_500, 'penalty_suspense' => 500, 'fees' => 0], $t, '2026-11-15', '2026-11-25');
        $this->assertSame(0, $q['early_settlement_fee_minor']);
        $this->assertSame(3_288, $q['interest_to_day_minor'], '500,000 × 24 % × 10 ÷ 365 = 3,287.67');
        $this->assertSame(500_000 + 12_000 + 3_288 + 2_000, $q['total_minor']);
        $this->assertSame(2400, Schedule::costPerYearBp(1_000_000, 240_000, 365));
        $this->assertSame(2401, Schedule::costPerYearBp(1_000_000, 240_001, 365), 'rounded up, never down past a cap');
        $this->assertSame(['fee' => 15_000, 'insurance' => 5_000], Schedule::upfrontCharges(1_000_000, new LoanTerms('reducing_annuity', 200, 'month', 'monthly', 12, 0, 100, 5_000, 50)));
    }

    public function test_bad_terms_are_refused(): void
    {
        $this->refused('frequency_not_available', fn () => $this->terms('reducing_annuity', 200, 'meeting'));
        $this->refused('invalid_terms', fn () => $this->terms('compound'));
        $this->refused('invalid_terms', fn () => $this->terms('reducing_annuity', 200, 'monthly', 3, 3));
        $this->refused('invalid_terms', fn () => new LoanTerms('flat', 200, 'month', 'monthly', 3, 0, allocationOrder: ['principal', 'interest']));
        $this->refused('invalid_amount', fn () => Schedule::build(0, $this->terms(), '2026-11-01'));
        $this->assertSame(['principal', 'interest', 'fees', 'penalty'], LoanTerms::fromArray(['method' => 'flat', 'rate_bp' => 1, 'term' => 2, 'allocation_order' => 'principal, interest,fees,penalty'])->allocationOrder);
        $terms = $this->terms('reducing_equal_principal', 175, 'fortnightly', 10, 1);
        $this->assertEquals($terms, LoanTerms::fromArray($terms->toArray()), 'the snapshot round-trips');
    }

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        yield 'seed 20261021' => [20261021];
        yield 'seed 7' => [7];
    }

    /** @dataProvider seeds */
    public function test_property_random_loans(int $seed): void
    {
        mt_srand($seed);
        $methods = ['reducing_annuity', 'reducing_equal_principal'];
        $frequencies = LoanTerms::FREQUENCIES;
        for ($case = 0; $case < 400; $case++) {
            $principal = mt_rand(1, 50) === 1 ? mt_rand(1_000, 9_999) : mt_rand(10_000, 50_000_000);
            $term = mt_rand(1, 60);
            $grace = $term > 1 ? mt_rand(0, min(3, $term - 1)) : 0;
            $rate = mt_rand(0, 12) === 0 ? 0 : mt_rand(25, 1000);
            $period = mt_rand(0, 1) ? 'month' : 'year';
            if ($period === 'year') {
                $rate *= 12;
            }
            $frequency = $frequencies[mt_rand(0, 2)];
            $method = $methods[mt_rand(0, 1)];
            $terms = new LoanTerms($method, $rate, $period, $frequency, $term, $grace);
            $first = Carbon::parse('2026-01-01')->addDays(mt_rand(0, 400));
            $label = "case {$case}: {$principal} {$method} {$rate}bp/{$period} {$frequency} ×{$term} grace {$grace}";

            $lines = Schedule::build($principal, $terms, $first);
            $this->assertCount($term, $lines, $label);
            $t = Schedule::totals($lines);
            $this->assertSame($principal, $t['principal'], "Σ principal = the loan ({$label})");
            $r = $terms->periodicRatio();
            $opening = $principal;
            $previous = null;
            foreach ($lines as $i => $l) {
                $this->assertSame($opening, $l->openingPrincipal, $label);
                $this->assertGreaterThanOrEqual(0, $l->principal, $label);
                $this->assertSame((int) bcadd(bcadd(bcmul((string) $l->openingPrincipal, $r, 20), '0.5', 20), '0', 0), $l->interest, "interest = opening × r, half-up ({$label})");
                if ($i < $grace) {
                    $this->assertSame(0, $l->principal, $label);
                }
                if ($previous !== null) {
                    $this->assertTrue($l->dueOn > $previous, $label);
                }
                $previous = $l->dueOn;
                $opening -= $l->principal;
            }
            $this->assertSame(0, $opening, $label);
            $amortising = array_slice($lines, $grace);
            if ($method === 'reducing_annuity' && $rate > 0 && count($amortising) > 1) {
                $a = Schedule::annuity($principal, $r, $term - $grace);
                $clamped = false;
                foreach (array_slice($amortising, 0, -1) as $l) {
                    $clamped = $clamped || $l->principal === 0;
                    if (! $clamped) {
                        $this->assertSame($a, $l->instalment(), "equal instalments ({$label})");
                    }
                }
                if (! $clamped) {
                    // Each line's two roundings (≤ ½ unit each) carry into the balance and grow with interest: at most Σ (1+r)^k units.
                    $bound = '0';
                    for ($k = 0, $m = count($amortising); $k < $m; $k++) {
                        $bound = bcadd($bound, bcpow(bcadd('1', $r, 20), (string) $k, 20), 20);
                    }
                    $this->assertLessThanOrEqual((int) bcadd($bound, '1', 0), abs(end($amortising)->instalment() - $a), "the last line only absorbs rounding ({$label})");
                }
            }
            if ($method === 'reducing_equal_principal' && count($amortising) > 1) {
                $each = $amortising[0]->principal;
                foreach (array_slice($amortising, 0, -1) as $l) {
                    $this->assertSame($each, $l->principal, $label);
                }
                $this->assertLessThan($each + count($amortising), end($amortising)->principal, $label);
                $this->assertGreaterThanOrEqual($each, end($amortising)->principal, $label);
            }
            $flat = Schedule::totals(Schedule::build($principal, new LoanTerms('flat', $rate, $period, $frequency, $term, $grace), $first));
            $this->assertSame($principal, $flat['principal'], $label);
            $this->assertLessThanOrEqual($flat['interest'], $t['interest'], "reducing never costs more than flat ({$label})");
            if ($principal >= 10_000) {
                $this->assertLessThanOrEqual($terms->annualRateBp() + 1, Schedule::costPerYearBp($principal, max(0, $t['interest'] - $term), $terms->nominalTermDays()),
                    "reducing interest stays within the contract rate a year ({$label})");
            }
        }
    }
}

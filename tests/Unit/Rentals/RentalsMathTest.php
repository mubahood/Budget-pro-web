<?php

namespace Tests\Unit\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Services\Rentals\Payments\Splits;
use App\Support\Rentals\Minor;
use App\Support\Rentals\Periods;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Estate Ledger's pure money and calendar maths (docs/estate-ledger/04-architecture.md §5.1, §5.3): amounts parse without floats,
 * periods follow the anchor with no month overflow, and commission on part payments always adds up to the whole.
 */
class RentalsMathTest extends TestCase
{
    public function test_amounts_parse_to_minor_units_and_refuse_what_could_be_wrong(): void
    {
        $this->assertSame(1250000, Minor::parse('1,250,000', 0));
        $this->assertSame(1250000, Minor::parse('1 250 000', 0));
        $this->assertSame(1250, Minor::parse('12.50', 2));
        $this->assertSame(1205, Minor::parse('12.05', 2));
        $this->assertSame('12.05', Minor::toMajor(1205, 2));
        $this->assertSame('-0.05', Minor::toMajor(-5, 2));
        foreach ([['12.5', 0], ['abc', 0], ['-5', 0], ['1.234', 2], ['', 0]] as [$v, $d]) {
            try {
                Minor::parse($v, $d);
                $this->fail("{$v} should be refused");
            } catch (BusinessRuleException $e) {
                $this->assertSame('amount_invalid', $e->errorCode());
            }
        }
        $this->assertSame(3, Minor::divRound(5, 2)); // halves up
        $this->assertSame(-3, Minor::divRound(-5, 2));
        $this->assertSame(30000, Minor::percent(300000, 1000));
        $this->assertSame(Minor::share(7, 1, 3), 2);
    }

    public function test_anchor_start_counts_every_period_from_the_start_with_no_overflow(): void
    {
        $p = Periods::between(Carbon::parse('2026-01-31'), 1, 'start', Carbon::parse('2026-01-31'), Carbon::parse('2026-04-30'));
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], array_map(fn ($x) => $x['start']->toDateString(), $p));
        $this->assertSame('2026-02-27', $p[0]['end']->toDateString());
        $this->assertSame('2026-03-30', $p[1]['end']->toDateString());

        $q = Periods::between(Carbon::parse('2026-01-15'), 3, 'start', Carbon::parse('2026-01-15'), Carbon::parse('2026-12-31'));
        $this->assertSame(['2026-01-15', '2026-04-15', '2026-07-15', '2026-10-15'], array_map(fn ($x) => $x['start']->toDateString(), $q));
        $this->assertSame('2026-04-14', $q[0]['end']->toDateString());

        // A stop day ends billing; $from skips periods already billed.
        $r = Periods::between(Carbon::parse('2026-01-15'), 1, 'start', Carbon::parse('2026-03-15'), Carbon::parse('2026-12-31'), Carbon::parse('2026-05-16'));
        $this->assertSame(['2026-03-15', '2026-04-15', '2026-05-15'], array_map(fn ($x) => $x['start']->toDateString(), $r));
    }

    public function test_anchor_first_bills_a_part_month_then_the_first_of_each_month(): void
    {
        $p = Periods::between(Carbon::parse('2026-03-20'), 1, 'first', Carbon::parse('2026-03-20'), Carbon::parse('2026-05-01'));
        $this->assertTrue($p[0]['part']);
        $this->assertSame(['2026-03-20', '2026-04-01', '2026-05-01'], array_map(fn ($x) => $x['start']->toDateString(), $p));
        $this->assertSame(12, $p[0]['days']);
        $this->assertSame(31, $p[0]['month_days']);
        $this->assertSame('2026-03-31', $p[0]['end']->toDateString());
        $this->assertFalse($p[1]['part']);

        $this->assertSame('2026-04-01', Periods::containing(Carbon::parse('2026-03-20'), 1, 'first', Carbon::parse('2026-04-17'))['start']->toDateString());
        $this->assertNull(Periods::containing(Carbon::parse('2026-03-20'), 1, 'first', Carbon::parse('2026-03-01')));
        $this->assertSame(-3, Periods::days(Carbon::parse('2026-03-10'), Carbon::parse('2026-03-07')));
    }

    public function test_commission_on_part_payments_is_proportional_and_exact_when_paid_in_full(): void
    {
        $bill = ['amount_minor' => 300000, 'goes_to' => 'landlord', 'commission_type' => 'percent', 'commission_bp' => 1000, 'commission_minor' => 0];
        $a = Splits::split($bill, 100000, 0, 'agency');
        $this->assertSame(['commission' => 10000, 'landlord' => 90000, 'agency' => 0, 'deposit' => 0], $a);
        $b = Splits::split($bill, 200000, 100000, 'agency');
        $this->assertSame(20000, $b['commission']);

        $flat = ['amount_minor' => 450000, 'goes_to' => 'landlord', 'commission_type' => 'flat', 'commission_bp' => 0, 'commission_minor' => 25000];
        $this->assertSame(25000, Splits::total(450000, 'flat', 0, 25000));
        $this->assertSame(['commission' => 0, 'landlord' => 0, 'agency' => 0, 'deposit' => 300000], Splits::split(['amount_minor' => 300000, 'goes_to' => 'deposit', 'commission_type' => 'none', 'commission_bp' => 0, 'commission_minor' => 0], 300000, 0, 'agency'));
        $this->assertSame(300000, Splits::split(['amount_minor' => 300000, 'goes_to' => 'deposit', 'commission_type' => 'none', 'commission_bp' => 0, 'commission_minor' => 0], 300000, 0, 'landlord')['landlord']);
        $this->assertSame(15000, Splits::split(['amount_minor' => 15000, 'goes_to' => 'agency', 'commission_type' => 'none', 'commission_bp' => 0, 'commission_minor' => 0], 15000, 0, 'agency')['agency']);

        // 2,000 random bills paid in random parts: the parts' commission always sums to the bill's, and splits add up.
        mt_srand(20261024);
        for ($i = 0; $i < 2000; $i++) {
            $amount = mt_rand(1, 3_000_000);
            $type = mt_rand(0, 1) ? 'percent' : 'flat';
            $charge = ['amount_minor' => $amount, 'goes_to' => 'landlord', 'commission_type' => $type, 'commission_bp' => mt_rand(0, 3000), 'commission_minor' => mt_rand(0, 100000)];
            $paid = 0;
            $sum = 0;
            while ($paid < $amount) {
                $a = min($amount - $paid, mt_rand(1, max(1, intdiv($amount, mt_rand(1, 6)))));
                $s = Splits::split($charge, $a, $paid, 'agency');
                $this->assertSame($a, $s['commission'] + $s['landlord']);
                $this->assertGreaterThanOrEqual(0, $s['commission']);
                $sum += $s['commission'];
                $paid += $a;
            }
            $this->assertSame(Splits::total($amount, $type, $charge['commission_bp'], $charge['commission_minor']), $sum, "bill {$i}");
        }
        $this->assertSame(['commission' => 25000, 'landlord' => 425000, 'agency' => 0, 'deposit' => 0], Splits::split($flat, 450000, 0, 'agency'));
    }
}

<?php

namespace Tests\Unit\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Support\Sacco\Amount;
use App\Support\Sacco\Currency;
use App\Support\Sacco\Rate;
use App\Support\Sacco\Rounding;
use Tests\TestCase;

/**
 * Akiba Ledger P1.3 (ADR 0009 §2, AK-08, AK-09): the SACCO's money is whole minor units, never a float. Parsing
 * refuses what could already be wrong, sums refuse overflow and mixed currencies, a rate rounds once, and a
 * split always adds up to the whole (10,000 random weight sets).
 */
class AmountTest extends TestCase
{
    private function refused(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected {$code}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    public function test_minor_unit_exponents_are_the_iso_ones(): void
    {
        $this->assertSame(0, Currency::exponent('UGX'));
        $this->assertSame(0, Currency::exponent('rwf'));
        $this->assertSame(2, Currency::exponent('KES'));
        $this->assertSame(2, Currency::exponent('TZS'), 'ISO gives TZS cents, whatever the shelf display does');
        $this->assertSame(2, Currency::exponent('USD'));
        $this->assertSame(3, Currency::exponent('KWD'));
        $this->assertSame(100, Currency::factor('KES'));
        $this->refused('invalid_currency', fn () => Currency::exponent('shillings'));
    }

    public function test_parsing_refuses_floats_excess_decimals_and_non_numbers(): void
    {
        $this->assertSame(1500, Amount::fromMajor('1500', 'UGX')->minor);
        $this->assertSame(1250, Amount::fromMajor('12.50', 'KES')->minor);
        $this->assertSame(1250, Amount::fromMajor('12.5', 'KES')->minor);
        $this->assertSame(-300, Amount::fromMajor('-3', 'USD')->minor);
        $this->assertSame(7, Amount::fromMajor(7, 'UGX')->minor);
        $this->assertSame('KES', Amount::fromMajor(' 1 ', 'kes')->currency);

        $this->refused('invalid_amount', fn () => Amount::fromMajor(0.1 + 0.2, 'KES'));
        $this->refused('invalid_amount', fn () => Amount::fromMajor(1500.0, 'UGX'));
        $this->refused('invalid_amount', fn () => Amount::fromMajor('12.5', 'UGX'));
        $this->refused('invalid_amount', fn () => Amount::fromMajor('12.505', 'KES'));
        foreach (['', 'abc', '1e5', '1,500', '+5', '5.', '.5', '0x1A'] as $bad) {
            $this->refused('invalid_amount', fn () => Amount::fromMajor($bad, 'UGX'));
        }
        $this->refused('amount_overflow', fn () => Amount::fromMajor('92233720368547758080', 'UGX'));
    }

    public function test_sums_refuse_overflow_and_mixed_currencies(): void
    {
        $a = Amount::of(1500, 'UGX');
        $this->assertSame(2000, $a->plus(Amount::of(500, 'UGX'))->minor);
        $this->assertSame(1000, $a->minus(Amount::of(500, 'UGX'))->minor);
        $this->assertSame(-1500, $a->negate()->minor);
        $this->assertSame(1500, $a->negate()->abs()->minor);
        $this->assertSame(1500, $a->minor, 'immutable');
        $this->assertTrue(Amount::zero('UGX')->isZero());
        $this->assertTrue($a->isPositive());
        $this->assertTrue($a->negate()->isNegative());
        $this->assertSame(1, $a->compare(Amount::of(1499, 'UGX')));
        $this->assertTrue($a->equals(Amount::of(1500, 'UGX')));
        $this->assertFalse($a->equals(Amount::of(1500, 'KES')));

        $this->refused('currency_mismatch', fn () => $a->plus(Amount::of(1, 'KES')));
        $this->refused('currency_mismatch', fn () => $a->compare(Amount::of(1, 'KES')));
        $this->refused('amount_overflow', fn () => Amount::of(PHP_INT_MAX, 'UGX')->plus(Amount::of(1, 'UGX')));
        $this->refused('amount_overflow', fn () => Amount::of(PHP_INT_MIN, 'UGX')->minus(Amount::of(1, 'UGX')));
        $this->refused('amount_overflow', fn () => Amount::of(PHP_INT_MIN, 'UGX')->negate());
        $this->refused('amount_overflow', fn () => Amount::of(PHP_INT_MAX, 'UGX')->times('2'));
        $this->assertSame(PHP_INT_MAX, Amount::of(PHP_INT_MAX - 1, 'UGX')->plus(Amount::of(1, 'UGX'))->minor);
    }

    public function test_times_rounds_half_up_once(): void
    {
        $this->assertSame(15, Amount::of(1000, 'UGX')->times(Rate::bp(150))->minor);
        $this->assertSame(3, Amount::of(5, 'UGX')->times('0.5')->minor, '2.5 rounds half-up to 3');
        $this->assertSame(3, Amount::of(5, 'UGX')->times('0.5', Rounding::HALF_UP)->minor);
        $this->assertSame(-3, Amount::of(-5, 'UGX')->times('0.5')->minor, 'half-up goes away from zero');
        $this->assertSame(2, Amount::of(5, 'UGX')->times('0.5', Rounding::DOWN)->minor);
        $this->assertSame(3, Amount::of(5, 'UGX')->times('0.41', Rounding::UP)->minor);
        $this->assertSame(2, Amount::of(5, 'UGX')->times('0.4', Rounding::UP)->minor, 'an exact result is not rounded');
        $this->assertSame(2, Amount::of(5, 'UGX')->times('0.49')->minor);
        // Rounded once: 1,001 × 0.15 % is 1.5015 → 2, where three roundings of 0.05 % (0.5005 → 1 each) would give 3.
        $this->assertSame(2, Amount::of(1001, 'UGX')->times('0.0015')->minor);
        $this->assertSame(3, Amount::of(1001, 'UGX')->times('0.0005')->minor * 3);
        // 1,000,000 at 18 %/year, one month: exactly 15,000.
        $this->assertSame(15000, Amount::of(1000000, 'UGX')->times(Rate::bp(1800)->monthly())->minor);
        $this->refused('invalid_rate', fn () => Amount::of(1, 'UGX')->times('1/12'));
    }

    public function test_display_and_json(): void
    {
        $this->assertSame('1500', Amount::of(1500, 'UGX')->toMajorString());
        $this->assertSame('12.50', Amount::of(1250, 'KES')->toMajorString());
        $this->assertSame('-0.05', Amount::of(-5, 'USD')->toMajorString());
        $this->assertSame('0.007', Amount::of(7, 'KWD')->toMajorString());
        $this->assertSame(['minor' => 1250, 'currency' => 'KES'], Amount::of(1250, 'KES')->jsonSerialize());
        $this->assertSame('{"minor":1500,"currency":"UGX"}', json_encode(Amount::of(1500, 'UGX')));
        $this->assertStringEndsWith('12.50', Amount::of(1250, 'KES')->format());
    }

    public function test_allocate_splits_exactly_and_keeps_keys(): void
    {
        $parts = Amount::of(100, 'UGX')->allocate(['a' => 1, 'b' => 1, 'c' => 1]);
        $this->assertSame(['a' => 34, 'b' => 33, 'c' => 33], array_map(fn (Amount $p) => $p->minor, $parts));
        $parts = Amount::of(-100, 'UGX')->allocate([3 => 1, 7 => 2, 9 => 0]);
        $this->assertSame([3 => -33, 7 => -67, 9 => 0], array_map(fn (Amount $p) => $p->minor, $parts));
        $this->refused('invalid_allocation', fn () => Amount::of(1, 'UGX')->allocate([]));
        $this->refused('invalid_allocation', fn () => Amount::of(1, 'UGX')->allocate([0, 0]));
        $this->refused('invalid_allocation', fn () => Amount::of(1, 'UGX')->allocate([1, -1]));
    }

    public function test_property_allocate_sums_exactly_for_ten_thousand_random_weight_sets(): void
    {
        mt_srand(20261021);
        for ($i = 0; $i < 10000; $i++) {
            $n = mt_rand(1, 12);
            $weights = [];
            for ($k = 0; $k < $n; $k++) {
                $weights[] = mt_rand(0, 3) === 0 ? 0 : mt_rand(1, mt_rand(0, 1) ? 1000 : 5000000000);
            }
            if (array_sum($weights) === 0) {
                $weights[0] = 1;
            }
            $minor = mt_rand(0, 4) === 0 ? mt_rand(-1000, 1000) : mt_rand(-PHP_INT_MAX >> 2, PHP_INT_MAX >> 2);
            $parts = Amount::of($minor, 'UGX')->allocate($weights);
            $sum = array_sum(array_map(fn (Amount $p) => $p->minor, $parts));
            $this->assertSame($minor, $sum, "seed step {$i}");
            $total = array_sum($weights);
            foreach ($weights as $k => $w) {
                if ($w === 0) {
                    $this->assertSame(0, $parts[$k]->minor);

                    continue;
                }
                // Each part is within one unit of its exact share.
                $exact = bcdiv(bcmul((string) abs($minor), (string) $w, 0), (string) $total, 4);
                $this->assertLessThan(1, abs((float) bcsub((string) abs($parts[$k]->minor), $exact, 4)));
            }
        }
    }

    public function test_rates(): void
    {
        $this->assertSame(150, Rate::percent('1.5')->bp);
        $this->assertSame(1800, Rate::percent('18')->bp);
        $this->assertSame('1.5%', Rate::bp(150)->percentString());
        $this->assertSame('18%', Rate::bp(1800)->percentString());
        $this->assertSame('0.25%', Rate::bp(25)->percentString());
        $this->assertSame('0.0150000000', Rate::bp(150)->ratio());
        $this->assertSame('0.0150000000', Rate::bp(1800)->monthly());
        $this->assertSame('0.0069230769', Rate::bp(1800)->perPeriod('fortnightly'));
        $this->assertSame(75, Rate::bp(150)->halfOf()->bp, 'the penalty cap is half the monthly rate');
        $this->assertSame(75, Rate::bp(151)->halfOf()->bp, 'and never rounded above the law');
        $this->assertSame(-1, Rate::bp(75)->compare(Rate::bp(150)));
        $this->assertTrue(Rate::zero()->isZero());
        $this->refused('invalid_rate', fn () => Rate::percent('1.555'));
        $this->refused('invalid_rate', fn () => Rate::percent('-1'));
        $this->refused('invalid_rate', fn () => Rate::bp(-1));
        $this->refused('invalid_frequency', fn () => Rate::bp(100)->perPeriod('hourly'));
    }
}

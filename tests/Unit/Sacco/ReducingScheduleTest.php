<?php

namespace Tests\Unit\Sacco;

use App\Services\Sacco\Loans\LoanTerms;
use App\Services\Sacco\Loans\Schedule;
use App\Support\Sacco\Amount;
use PHPUnit\Framework\TestCase;

/** The reducing-balance schedule over many random loans: principal sums to the loan, the balance ends at zero, interest is the balance × rate. */
class ReducingScheduleTest extends TestCase
{
    public function test_reducing_balance_property(): void
    {
        mt_srand(4242);
        for ($run = 0; $run < 1500; $run++) {
            $months = mt_rand(1, 60);
            $principal = mt_rand($months, 500_000_000);
            $rate = mt_rand(0, 1000); // up to 10 % a month
            $terms = new LoanTerms('reducing_annuity', $rate, 'month', 'monthly', $months);
            $lines = Schedule::build($principal, $terms, '2026-11-21');
            $this->assertCount($months, $lines);
            $this->assertSame($principal, array_sum(array_map(fn ($l) => $l->principal, $lines)), "run {$run}");
            $balance = $principal;
            foreach ($lines as $i => $l) {
                $this->assertSame($balance, $l->openingPrincipal);
                $this->assertSame(Amount::of($balance, 'UGX')->times(bcdiv((string) $rate, '10000', 20))->minor, $l->interest, 'interest on the balance');
                $this->assertGreaterThanOrEqual(0, $l->principal);
                $balance -= $l->principal;
                if ($i < $months - 1 && $rate > 0) {
                    $this->assertLessThanOrEqual(1, abs($lines[0]->instalment() - $l->instalment()), 'equal monthly instalments');
                }
            }
            $this->assertSame(0, $balance);
            $this->assertSame(substr($lines[0]->dueOn, 0, 7), '2026-11');
        }
    }
}

<?php

namespace Tests\Unit\Sacco;

use App\Services\Sacco\Simple\ShareOut;
use PHPUnit\Framework\TestCase;

/** The share-out split, over many random SACCOs: the lines add up to the amount exactly and follow the rule. */
class ShareOutAllocationPropertyTest extends TestCase
{
    public function test_the_lines_always_add_up_to_the_amount(): void
    {
        mt_srand(20261021);
        $svc = new ShareOut();
        for ($run = 0; $run < 2000; $run++) {
            $n = mt_rand(1, 40);
            $members = [];
            for ($i = 1; $i <= $n; $i++) {
                $accounts = [];
                for ($k = 0, $c = mt_rand(0, 2); $k < $c; $k++) {
                    $accounts[$i * 10 + $k] = mt_rand(0, 9) === 0 ? 0 : mt_rand(1, 50_000_000);
                }
                $members[$i] = ['member_id' => $i, 'savings_minor' => array_sum($accounts), 'shares' => mt_rand(0, 3) === 0 ? 0 : mt_rand(1, 500), 'accounts' => $accounts];
            }
            $savings = array_sum(array_column($members, 'savings_minor'));
            $profit = mt_rand(0, 3) === 0 ? 0 : mt_rand(1, 90_000_000);
            $amount = mt_rand(0, $savings + $profit);
            $weights = array_sum(array_column($members, 'shares')) + $savings;
            if ($weights === 0) {
                try {
                    $svc->allocate(['members' => $members], $amount);
                    $this->assertSame(0, $amount, 'profit with nobody to share it by is refused');
                } catch (\App\Exceptions\BusinessRuleException $e) {
                    $this->assertSame('nothing_to_share', $e->errorCode());
                }

                continue;
            }
            $lines = $svc->allocate(['members' => $members], $amount);

            $this->assertSame($amount, array_sum(array_column($lines, 'payout_minor')), "run {$run}");
            $sharesTotal = array_sum(array_column($members, 'shares'));
            foreach ($lines as $l) {
                $m = $members[$l['member_id']];
                $this->assertLessThanOrEqual($m['savings_minor'], $l['savings_paid_minor']);
                $this->assertSame($l['savings_paid_minor'], array_sum($l['accounts']));
                foreach ($l['accounts'] as $id => $paid) {
                    $this->assertLessThanOrEqual($m['accounts'][$id], $paid);
                }
                if ($amount >= $savings) {
                    $this->assertSame($m['savings_minor'], $l['savings_paid_minor']);
                    $weight = $sharesTotal > 0 ? $m['shares'] / $sharesTotal : ($savings > 0 ? $m['savings_minor'] / $savings : 0);
                    $this->assertEqualsWithDelta(($amount - $savings) * $weight, $l['profit_minor'], 1.0);
                } else {
                    $this->assertSame(0, $l['profit_minor']);
                    $this->assertEqualsWithDelta($savings > 0 ? $amount * $m['savings_minor'] / $savings : 0, $l['savings_paid_minor'], 1.0);
                }
            }
        }
    }
}

<?php

namespace Tests\Feature\Sacco;

use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Loans\Repayments;
use App\Services\Sacco\Reports\SaccoReports;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.19 (04 §12): the loan reports' golden figures on a fixed book: one loan current, one 70 days late;
 * PAR, the Form 1 bands with their provisions, the arrears list, what was paid out and repaid, a loan's statement
 * and the guarantors' exposure.
 */
class LoanReportsTest extends ApiTestCase
{
    use MakesSaccoLoans;

    public function test_golden_figures(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $good = $this->loanMember($company, $owner, 900, ['first_name' => 'Prompt']);
        $this->loanSavings($company, $good, 2_000_000, $owner, 'ORD', now()->subDays(200)->toDateString());
        $late = $this->loanMember($company, $owner, 900, ['first_name' => 'Tardy']);
        $this->loanSavings($company, $late, 2_000_000, $owner, 'ORD', now()->subDays(200)->toDateString());
        $current = $this->activeLoan($company, $good, 1_200_000, 12, ['channel' => 'savings'], now()->subDays(10)->toDateString());
        $behind = $this->activeLoan($company, $late, 600_000, 6, ['channel' => 'savings'], now()->subMonthsNoOverflow(1)->subDays(70)->toDateString());
        (new LoanJobs())->run($cid);
        $behind->refresh();
        (new Repayments())->record($company, (int) $current->id, ['amount_minor' => 200_000, 'channel' => 'cash'], $owner);
        $r = new SaccoReports();
        $catalog = SaccoReports::catalog();
        foreach (['portfolio', 'par', 'aging', 'arrears_list', 'disbursements', 'repayments', 'loan_statement', 'guarantor_exposure'] as $key) {
            $this->assertArrayHasKey($key, $catalog);
        }
        $today = now()->toDateString();

        $par = collect($r->build($company, 'par', ['to' => $today], $owner)['rows'])->keyBy('measure');
        $gross = 1_000_000 + 600_000;
        $this->assertSame($gross, $par['PAR1']['gross_minor']);
        $this->assertSame([600_000, 600_000, 600_000, 0], [$par['PAR1']['principal_minor'], $par['PAR30']['principal_minor'], $par['PAR60']['principal_minor'], $par['PAR90']['principal_minor']]);
        $this->assertSame(3750, $par['PAR30']['ratio_bp'], '600,000 ÷ 1,600,000');

        $aging = collect($r->build($company, 'aging', ['to' => $today], $owner)['rows'])->keyBy('band');
        $this->assertSame([1, 1_000_000, 10_000], [$aging['performing']['loans'], $aging['performing']['principal_minor'], $aging['performing']['required_minor']]);
        $this->assertSame([1, 600_000, 150_000], [$aging['substandard']['loans'], $aging['substandard']['principal_minor'], $aging['substandard']['required_minor']], '25 %');

        $arrears = $r->build($company, 'arrears_list', ['to' => $today], $owner);
        $this->assertCount(1, $arrears['rows']);
        $this->assertSame([$behind->loan_no, (int) $behind->first_due_on->diffInDays(now()->startOfDay()), 'substandard'], [$arrears['rows'][0]['loan_no'], $arrears['rows'][0]['days_past_due'], $arrears['rows'][0]['classification']]);

        $port = $r->build($company, 'portfolio', ['to' => $today], $owner);
        $this->assertSame(['principal_minor' => 1_600_000, 'loans' => 2], $port['totals']);
        $out = $r->build($company, 'disbursements', ['from' => now()->subYear()->toDateString(), 'to' => $today], $owner);
        $this->assertSame(['principal_minor' => 1_800_000, 'net_minor' => 1_782_000], $out['totals'], 'the 1 % fee taken');
        $in = $r->build($company, 'repayments', ['from' => $today, 'to' => $today], $owner);
        $this->assertSame([200_000, 200_000], [$in['totals']['amount_minor'], $in['totals']['principal_minor']]);
        $st = $r->build($company, 'loan_statement', ['loan_id' => $current->id, 'to' => $today], $owner);
        $this->assertSame(1_000_000, $st['totals']['owed_minor']);
        $this->assertSame([1_200_000, 200_000], [$st['rows'][0]['debit_minor'], end($st['rows'])['credit_minor']]);
        $this->assertSame([], $r->build($company, 'guarantor_exposure', ['to' => $today], $owner)['rows']);
        $this->assertSame('forbidden', $this->errorCode(fn () => $r->build($company, 'par', ['to' => $today], $this->saccoStaff($company, 'sacco_teller'))));
    }
}

<?php

namespace Tests\Feature\Sacco;

use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\Reports\SaccoReports;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P4.2 and P4.11: Form 1 (risk classification and provisioning, normal and rescheduled blocks) and Form 7
 * (non-performing loans, interest in suspense, insider loans by category) from the loan book, and the insider loans
 * register. A 1,000,000 loan more than 180 days late (loss, 100 %) and a 500,000 loan just paid out (performing, 1 %),
 * whose borrower then joins the committee.
 */
class UmraLoanFormsTest extends ApiTestCase
{
    use MakesSaccoLoans;

    public function test_form_1_form_7_and_the_insider_register(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $m1 = $this->loanMember($company, $owner, 900);
        $this->loanSavings($company, $m1, 1_000_000, $owner, 'ORD', now()->subDays(300)->toDateString());
        $bad = $this->activeLoan($company, $m1, 1_000_000, 12, ['channel' => 'savings'], now()->subDays(250)->toDateString());
        $m2 = $this->loanMember($company, $owner, 900);
        $this->loanSavings($company, $m2, 500_000, $owner, 'ORD', now()->subDays(300)->toDateString());
        $this->activeLoan($company, $m2, 500_000, 12, ['channel' => 'savings'], now()->subDays(5)->toDateString());
        (new LoanJobs())->run($cid);
        (new OfficerService())->appoint($cid, ['position' => 'board_member', 'member_id' => (int) $m2->id, 'starts_on' => now()->subDays(2)->toDateString()], (int) $owner->id);
        $bad->refresh();
        $this->assertSame('loss', $bad->classification);
        $today = now()->toDateString();

        $f1 = (new SaccoReports())->build($company, 'umra_f1', ['to' => $today], $owner);
        $rows = collect($f1['rows'])->keyBy('line');
        $this->assertSame([500_000, 100, 5_000], [$rows['A.1']['amount_minor'], $rows['A.1']['rate_bp'], $rows['A.1']['provision_minor']], 'performing at 1 %');
        $this->assertSame([1_000_000, 10000, 1_000_000], [$rows['A.5']['amount_minor'], $rows['A.5']['rate_bp'], $rows['A.5']['provision_minor']], 'loss at 100 %');
        $this->assertSame([1_500_000, 1_005_000], [$rows['A.6']['amount_minor'], $rows['A.6']['provision_minor']]);
        $this->assertSame([0, 0], [$rows['B.6']['amount_minor'], $rows['B.6']['provision_minor']], 'nothing rescheduled');
        $this->assertSame([1_500_000, 1_005_000], [$rows['C']['amount_minor'], $rows['C']['provision_minor']]);
        $this->assertSame(['A', 'A.1', 'A.2', 'A.3', 'A.4', 'A.5', 'A.6', 'B', 'B.1', 'B.2', 'B.3', 'B.4', 'B.5', 'B.6', 'C'], array_column($f1['rows'], 'line'), 'Schedule 4 order');

        $f7 = (new SaccoReports())->build($company, 'umra_f7', ['to' => $today], $owner)['meta']['lines'];
        $suspense = (int) $bad->interest_in_suspense_minor + (int) $bad->penalty_in_suspense_minor;
        $this->assertGreaterThan(0, $suspense, 'interest stopped going to income once the loan went bad');
        $this->assertSame([1_000_000, $suspense, 1_000_000 + $suspense], [$f7['1.1'], $f7['1.2'], $f7['1.3']]);
        $this->assertSame([500_000, 0, 500_000], [$f7['2.1'], $f7['2.2'], $f7['2.3']], 'the board member\'s loan is a director\'s insider loan');

        $insiders = (new SaccoReports())->build($company, 'insider_loans', ['to' => $today], $owner);
        $this->assertCount(1, $insiders['rows']);
        $this->assertSame(['Director', 500_000], [$insiders['rows'][0]['category'], $insiders['rows'][0]['principal_minor']]);
        $this->assertSame(['Director' => 500_000, 'Employee' => 0], $insiders['meta']['by_category']);
    }
}

<?php

namespace Tests\Feature\Sacco\Simple;

use App\Models\SaccoInterestRun;
use App\Models\SaccoLoan;
use App\Models\SaccoPeriod;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * The daily run's month-end work is the SACCO's, not its loans': a new SACCO with savings and no loan yet gets each
 * ended month's savings interest from LoanJobs::run, and can then close the month (the close waits for that interest).
 */
class MonthEndWithoutLoansTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_sacco_with_savings_and_no_loans_gets_its_interest_and_closes_the_month(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $owner = $s['owner'];
        $cid = (int) $c->id;
        (new PeriodService())->ensureAhead($cid, Carbon::parse('2026-07-01'), 6);
        $p = (new SavingsProducts())->create($c, ['code' => 'INT', 'name' => 'INT savings', 'kind' => 'voluntary', 'interest_rate_bp' => 1200,
            'interest_basis' => 'daily_balance', 'interest_posting' => 'monthly'], $owner);
        $a = (new SavingsAccounts())->open($c, (int) $this->activeMember($c, $owner)->id, (int) $p->id, $owner, ['opened_on' => '2026-07-01']);
        $this->deposit($c, $a, 1_000_000, $owner, ['value_date' => '2026-07-01'], ['entry_date' => '2026-07-01']);
        $this->assertSame(0, SaccoLoan::where('company_id', $cid)->count());

        $july = SaccoPeriod::where('company_id', $cid)->where('year', 2026)->where('month', 7)->firstOrFail();
        $this->assertSame('interest_pending', $this->code(fn () => (new PeriodService())->close($cid, (int) $july->id)));

        (new LoanJobs())->run($cid);

        $months = SaccoInterestRun::where('company_id', $cid)->where('kind', 'accrual')->get()->map(fn ($r) => $r->period_from->format('Y-m'))->sort()->values()->all();
        $this->assertSame(['2026-07', '2026-08', '2026-09'], $months, 'every ended month, none for October');
        $this->assertGreaterThan(1_000_000, $this->balanceOf((int) $a->id), 'monthly posting: credited to the member');
        $this->assertSame('closed', (new PeriodService())->close($cid, (int) $july->id)->status);

        $runs = SaccoInterestRun::where('company_id', $cid)->count();
        (new LoanJobs())->run($cid);
        $this->assertSame($runs, SaccoInterestRun::where('company_id', $cid)->count(), 'a second run posts nothing twice');
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }
}

<?php

namespace Tests\Feature\Sacco;

use App\Models\Company;
use App\Models\SaccoInterestRun;
use App\Models\SaccoInterestRunLine;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoPeriod;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use App\Services\Sacco\YearEnd\SavingsInterest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P4.6 (04 §5.1, ADR 0009 §4.4 `sint:{run_id}`): savings interest accrues each month (Dr 5100 / Cr 2140)
 * on each account's balance-days or lowest balance, the month's total rounded once and shared exactly; it is credited
 * to the accounts at the end of each posting period (Dr 2140 / Cr 21x0); every run happens once; a month does not
 * close before its interest is worked out; an account closed before the credit gives its share back to the expense.
 */
class SavingsInterestTest extends ApiTestCase
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

    /** @return array{0: Company, 1: User} */
    private function books(): array
    {
        $s = $this->saccoCompany();
        (new PeriodService())->ensureAhead((int) $s['company']->id, Carbon::parse('2026-05-01'), 6);

        return [$s['company'], $s['owner']];
    }

    private function product(Company $c, User $by, string $code, int $rateBp, string $basis, string $posting): SaccoSavingsProduct
    {
        return (new SavingsProducts())->create($c, ['code' => $code, 'name' => $code.' savings', 'kind' => 'voluntary', 'interest_rate_bp' => $rateBp,
            'interest_basis' => $basis, 'interest_posting' => $posting], $by);
    }

    private function account(Company $c, User $by, SaccoSavingsProduct $p, string $openedOn): SaccoSavingsAccount
    {
        $m = $this->activeMember($c, $by);

        return (new SavingsAccounts())->open($c, (int) $m->id, (int) $p->id, $by, ['opened_on' => $openedOn]);
    }

    private function putIn(Company $c, SaccoSavingsAccount $a, int $minor, User $by, string $on): void
    {
        $this->deposit($c, $a, $minor, $by, ['value_date' => $on], ['entry_date' => $on]);
    }

    private function bal(int $cid, string $key): int
    {
        return (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, $key))->value('balance_minor');
    }

    public function test_daily_balance_accrual_golden_figures_credit_and_idempotency(): void
    {
        [$c, $owner] = $this->books();
        $cid = (int) $c->id;
        $p = $this->product($c, $owner, 'INT', 1200, 'daily_balance', 'monthly');
        $a = $this->account($c, $owner, $p, '2026-05-10');
        $b = $this->account($c, $owner, $p, '2026-05-10');
        $d = $this->account($c, $owner, $p, '2026-05-10');
        $this->putIn($c, $a, 1_000_000, $owner, '2026-05-20');
        $this->putIn($c, $d, 333_333, $owner, '2026-05-20');
        $this->putIn($c, $b, 500_000, $owner, '2026-06-16'); // 15 of June's 30 days

        $pv = (new SavingsInterest())->preview($cid, (int) $p->id, '2026-06');
        // balance-days 30,000,000 + 7,500,000 + 9,999,990 = 47,499,990 × 12 % / 365 = 15,616.435 → 15,616
        $this->assertSame(15_616, $pv['total_minor']);
        $byAccount = array_column($pv['lines'], 'amount_minor', 'savings_account_id');
        $this->assertSame([$a->id => 9_863, $b->id => 2_466, $d->id => 3_287], $byAccount, 'largest remainder over the exact interest');
        $this->assertSame([30_000_000, 7_500_000, 9_999_990], array_column($pv['lines'], 'basis_minor'));

        $out = (new SavingsInterest())->run($cid, '2026-06');
        $this->assertSame([15_616, 15_616], [$out['accrued_minor'], $out['credited_minor']], 'monthly posting: accrued and credited the same month');
        $this->assertSame([1_009_863, 502_466, 336_620], [$this->balanceOf($a->id), $this->balanceOf($b->id), $this->balanceOf($d->id)]);
        $this->assertSame(0, $this->bal($cid, 'savings.interest_payable'), '2140 nets out once credited');
        $this->assertSame(15_616, $this->bal($cid, 'exp.savings_interest'));
        $accrual = SaccoInterestRun::where('company_id', $cid)->where('kind', 'accrual')->firstOrFail();
        $entry = SaccoJournalEntry::findOrFail($accrual->entry_id);
        $this->assertSame(['savings_interest', 'sint:'.$accrual->id, '2026-06-30', '2026-06-30'], [$entry->kind, $entry->idempotency_key, $entry->entry_date->toDateString(),
            $entry->value_date->toDateString()], 'dated the month\'s last day while June is open');

        $entries = SaccoJournalEntry::where('company_id', $cid)->count();
        $again = (new SavingsInterest())->run($cid, '2026-06');
        $this->assertSame($out['runs'], $again['runs'], 'the same runs');
        $this->assertSame($entries, SaccoJournalEntry::where('company_id', $cid)->count(), 'nothing posted twice');
        $this->assertSame(1_009_863, $this->balanceOf($a->id));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
        $this->assertSame('month_not_ended', $this->code(fn () => (new SavingsInterest())->run($cid, '2026-10')));
    }

    public function test_quarterly_credit_minimum_balance_and_a_closed_account_gives_its_share_back(): void
    {
        [$c, $owner] = $this->books();
        $cid = (int) $c->id;
        $q = $this->product($c, $owner, 'QTR', 600, 'min_monthly_balance', 'quarterly');
        $a = $this->account($c, $owner, $q, '2026-06-01');
        $gone = $this->account($c, $owner, $q, '2026-06-01');
        $this->putIn($c, $a, 200_000, $owner, '2026-06-10');
        $this->putIn($c, $gone, 120_000, $owner, '2026-06-10');
        $this->withdraw($c, $a, 50_000, $owner, ['value_date' => '2026-07-15']); // posted today, value date July: July's lowest is 150,000

        $interest = new SavingsInterest();
        $interest->run($cid, '2026-07');
        $july = SaccoInterestRunLine::where('company_id', $cid)->where('savings_account_id', $a->id)->firstOrFail();
        $this->assertSame([150_000, 750], [$july->basis_minor, $july->amount_minor], '150,000 × 6 % ÷ 12');
        $this->assertSame(0, SaccoInterestRun::where('company_id', $cid)->where('kind', 'credit')->count(), 'July ends no quarter');
        $this->assertSame(750 + 600, $this->bal($cid, 'savings.interest_payable'), '120,000 × 6 % ÷ 12 = 600 for the other account');

        $interest->run($cid, '2026-08');
        // the second account is emptied and closed in September, before the quarter's interest is credited
        $this->withdraw($c, $gone, 120_000, $owner, ['value_date' => '2026-09-05']);
        (new SavingsAccounts())->close($c, (int) $gone->id, 'Moved away', $owner);
        $interest->run($cid, '2026-09');
        $credit = SaccoInterestRun::where('company_id', $cid)->where('kind', 'credit')->firstOrFail();
        $this->assertSame(['2026-07-01', '2026-09-30', 750 + 750 + 750], [$credit->period_from->toDateString(), $credit->period_to->toDateString(), $credit->total_minor],
            'July, August and September for the open account, 750 each');
        $this->assertSame(1_200, $credit->released_minor, 'the closed account\'s July and August go back to 5100');
        $this->assertSame(0, $this->bal($cid, 'savings.interest_payable'));
        $this->assertSame(150_000 + 2_250, $this->balanceOf($a->id));
        $this->assertSame(0, $this->balanceOf($gone->id), 'a closed account takes nothing');
        $this->assertSame(2_250, $this->bal($cid, 'exp.savings_interest'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_a_month_closes_only_once_its_interest_is_worked_out_and_an_officer_can_run_it(): void
    {
        [$c, $owner] = $this->books();
        $cid = (int) $c->id;
        $p = $this->product($c, $owner, 'INT', 1200, 'daily_balance', 'annually');
        $this->putIn($c, $this->account($c, $owner, $p, '2026-05-01'), 1_000_000, $owner, '2026-05-01');
        $may = SaccoPeriod::where('company_id', $cid)->where('year', 2026)->where('month', 5)->firstOrFail();
        $this->assertSame('interest_pending', $this->code(fn () => (new PeriodService())->close($cid, (int) $may->id)));

        $treasurer = $this->saccoStaff($c, 'sacco_treasurer');
        $approval = (new SaccoApprovals())->request($c, 'interest_run', ['month' => '2026-05'], $owner);
        $this->assertSame(10_192, $approval->amount_minor, '1,000,000 × 31 days × 12 % / 365 = 10,191.78');
        $this->assertSame('self_approval', $this->code(fn () => (new SaccoApprovals())->approve($c, (int) $approval->id, $owner)));
        $done = (new SaccoApprovals())->approve($c, (int) $approval->id, $treasurer);
        $this->assertSame('executed', $done->status);
        $this->assertSame(10_192, $this->bal($cid, 'savings.interest_payable'), 'annual posting: it waits in 2140 until the year ends');
        $this->assertSame('closed', (new PeriodService())->close($cid, (int) $may->id)->status);
        $this->assertSame(1, SaccoInterestRun::where('company_id', $cid)->where('kind', 'accrual')->count());
    }

    public function test_property_the_lines_always_add_up_to_the_rounded_total(): void
    {
        [$c, $owner] = $this->books();
        $cid = (int) $c->id;
        $p = $this->product($c, $owner, 'PRP', 777, 'daily_balance', 'monthly');
        mt_srand(4242);
        for ($i = 0; $i < 12; $i++) {
            $a = $this->account($c, $owner, $p, '2026-05-01');
            for ($k = 0; $k < 3; $k++) {
                $this->putIn($c, $a, mt_rand(1, 9_999_999), $owner, '2026-06-'.str_pad((string) mt_rand(1, 30), 2, '0', STR_PAD_LEFT));
            }
        }
        foreach (['2026-06', '2026-07', '2026-08'] as $month) {
            $pv = (new SavingsInterest())->preview($cid, (int) $p->id, $month);
            $exact = '0';
            foreach ($pv['lines'] as $l) {
                $one = bcdiv(bcmul((string) $l['basis_minor'], '777', 0), (string) (10000 * 365), 10);
                $exact = bcadd($exact, $one, 10);
                $this->assertLessThan(1, abs((float) bcsub((string) $l['amount_minor'], $one, 10)), 'each line is within one unit of its exact interest');
            }
            $this->assertSame((int) bcadd($exact, '0.5', 0), $pv['total_minor'], 'the total is the exact sum rounded once');
            $this->assertSame($pv['total_minor'], array_sum(array_column($pv['lines'], 'amount_minor')), 'the lines add up exactly');
            (new SavingsInterest())->run($cid, $month);
        }
        $this->assertSame(0, $this->bal($cid, 'savings.interest_payable'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_tenancy_one_sacco_never_runs_anothers_product(): void
    {
        [$c] = $this->books();
        [$other, $otherOwner] = $this->books();
        $p = $this->product($other, $otherOwner, 'OTH', 500, 'daily_balance', 'monthly');
        $this->assertSame('product_not_found', $this->code(fn () => (new SavingsInterest())->accrue((int) $c->id, (int) $p->id, '2026-06')));
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

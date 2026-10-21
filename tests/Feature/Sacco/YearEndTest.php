<?php

namespace Tests\Feature\Sacco;

use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoDividendLine;
use App\Models\SaccoDividendRun;
use App\Models\SaccoFiscalYear;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoPeriod;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\Reports\Figures;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\Shares\ShareService;
use App\Services\Sacco\YearEnd\Appropriation;
use App\Services\Sacco\YearEnd\Closing;
use App\Services\Sacco\YearEnd\Dividends;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P4.7 and P4.8 (04 §5.1, §7, AK-18, AK-69) on a fixed fictional year: 2025's income and expenses close
 * to 3410 (close:2025) by two officers; the AGM's waterfall empties 3410 into the reserve, the education lines, the
 * transfer fund, other reserves, dividends payable and retained earnings; the dividend is shared exactly over the
 * shares and paid to savings or in cash. A licensed SACCO is refused above the law's limits and without the
 * Registrar's consent; any other SACCO goes ahead with warnings.
 */
class YearEndTest extends ApiTestCase
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

    private function officer(Company $c, string $role, string $position): User
    {
        $u = $this->saccoStaff($c, $role);
        (new OfficerService())->appoint((int) $c->id, ['position' => $position, 'user_id' => (int) $u->id, 'starts_on' => '2024-01-01'], (int) $c->owner_id);

        return $u;
    }

    private function journal(int $cid, string $on, string $debit, string $credit, int $minor, string $key): void
    {
        (new PostingService())->post(JournalDraft::make($cid, 'manual', $key)->debit(ChartOfAccounts::id($cid, $debit), Amount::of($minor, 'UGX'))
            ->credit((int) SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where(fn ($q) => $q->where('system_key', $credit)->orWhere('code', $credit))->value('id'),
                Amount::of($minor, 'UGX'))->dated($on, $on));
    }

    private function journalTo(int $cid, string $on, string $debitCode, string $credit, int $minor, string $key): void
    {
        (new PostingService())->post(JournalDraft::make($cid, 'manual', $key)
            ->debit((int) SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', $debitCode)->value('id'), Amount::of($minor, 'UGX'))
            ->credit(ChartOfAccounts::id($cid, $credit), Amount::of($minor, 'UGX'))->dated($on, $on));
    }

    /**
     * The fixed year: four members holding 300,000 / 300,000 / 300,000 / 100,000 in shares (1,000,000), 4,000,000 in
     * savings (three accounts), entrance fees 80,000, loan interest 8,000,000 and fees 2,000,000 in; personnel
     * 4,000,000 and software 580,000 out. Surplus 5,500,000; total assets 10,500,000. Every 2025 month closed.
     *
     * @return array{company: Company, owner: User, members: list<\App\Models\SaccoMember>, savings: list<\App\Models\SaccoSavingsAccount>, treasurer: User, vice: User}
     */
    private function year(bool $licensed = true): array
    {
        $s = $this->saccoCompany(licensed: $licensed);
        $c = $s['company'];
        $cid = (int) $c->id;
        $owner = $s['owner'];
        (new PeriodService())->ensureAhead($cid, Carbon::parse('2025-01-01'), 22);
        $members = [];
        $savings = [];
        foreach ([300_000, 300_000, 300_000, 100_000] as $i => $shares) {
            $m = $this->activeMember($c, $owner, 'Member', chr(65 + $i));
            $members[] = $m;
            (new ShareService())->payEntranceFee($c, ['member_id' => $m->id, 'value_date' => '2025-02-0'.($i + 1)], $owner, ['entry_date' => '2025-02-0'.($i + 1)]);
            (new ShareService())->purchase($c, ['member_id' => $m->id, 'amount_minor' => $shares, 'channel' => 'cash', 'value_date' => '2025-03-0'.($i + 1)], $owner, ['entry_date' => '2025-03-0'.($i + 1)]);
            if ($i < 3) {
                $a = (new \App\Services\Sacco\Savings\SavingsAccounts())->open($c, (int) $m->id, (int) $this->product($c)->id, $owner, ['opened_on' => '2025-02-01']);
                $this->deposit($c, $a, [2_000_000, 1_500_000, 500_000][$i], $owner, ['value_date' => '2025-04-10'], ['entry_date' => '2025-04-10']);
                $savings[] = $a;
            }
        }
        $this->journal($cid, '2025-06-30', 'bank.main', '4100', 8_000_000, 'test:int');
        $this->journal($cid, '2025-09-30', 'bank.main', '4420', 2_000_000, 'test:fees');
        $this->journalTo($cid, '2025-11-30', '5300', 'bank.main', 4_000_000, 'test:staff');
        $this->journalTo($cid, '2025-12-15', '5740', 'bank.main', 580_000, 'test:software');
        foreach (SaccoPeriod::where('company_id', $cid)->where('year', 2025)->orderBy('month')->get() as $p) {
            (new PeriodService())->close($cid, (int) $p->id);
        }

        return ['company' => $c->fresh(), 'owner' => $owner, 'members' => $members, 'savings' => $savings,
            'treasurer' => $this->officer($c, 'sacco_treasurer', 'treasurer'), 'vice' => $this->officer($c, 'sacco_manager', 'vice_chair')];
    }

    private function bal(int $cid, string $key): int
    {
        return (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, $key))->value('balance_minor');
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

    /** @param array<string, mixed> $y */
    private function closeYear(array $y): void
    {
        $a = (new Closing())->request($y['company'], 2025, $y['owner'], ['on' => '2026-01-15']);
        (new SaccoApprovals())->approve($y['company'], (int) $a->id, $y['treasurer']);
        (new SaccoApprovals())->approve($y['company'], (int) $a->id, $y['vice']);
    }

    /** @param array<string, mixed> $y @param array<string, mixed> $data */
    private function appropriate(array $y, array $data): SaccoApproval
    {
        $a = (new Appropriation())->request($y['company'], 2025, $data + ['agm_date' => '2026-03-20', 'on' => '2026-03-20'], $y['owner']);
        (new SaccoApprovals())->approve($y['company'], (int) $a->id, $y['treasurer']);

        return (new SaccoApprovals())->approve($y['company'], (int) $a->id, $y['vice']);
    }

    private function consentFile(int $cid): int
    {
        return (int) \App\Models\StoredFile::create(['company_id' => $cid, 'uuid' => (string) Str::uuid(), 'purpose' => 'sacco_registrar_consent',
            'path' => 'sacco/test/consent.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1000])->id;
    }

    public function test_the_close_moves_the_year_to_3410_with_two_officers_and_the_statements_tie(): void
    {
        $y = $this->year();
        $c = $y['company'];
        $cid = (int) $c->id;
        $p = (new Closing())->preview($cid, 2025);
        $this->assertSame([10_080_000, 4_580_000, 5_500_000], [$p['income_minor'], $p['expense_minor'], $p['surplus_minor']]);
        $this->assertSame('year_not_ended', $this->code(fn () => (new Closing())->request($c, 2026, $y['owner'])));
        $this->assertSame('invalid_date', $this->code(fn () => (new Closing())->request($c, 2025, $y['owner'], ['on' => '2025-12-31'])));

        $a = (new Closing())->request($c, 2025, $y['owner'], ['on' => '2026-01-15']);
        $this->assertSame(2, $a->required_approvals);
        $this->assertSame('self_approval', $this->code(fn () => (new SaccoApprovals())->approve($c, (int) $a->id, $y['owner'])));
        $this->assertSame('position_required', $this->code(fn () => (new SaccoApprovals())->approve($c, (int) $a->id, $this->saccoStaff($c, 'sacco_manager'))));
        (new SaccoApprovals())->approve($c, (int) $a->id, $y['treasurer']);
        $this->assertSame('pending', $a->fresh()->status, 'two approvals');
        $this->assertSame(0, $this->bal($cid, 're.current'));
        $done = (new SaccoApprovals())->approve($c, (int) $a->id, $y['vice']);
        $this->assertSame('executed', $done->status);

        $this->assertSame(5_500_000, $this->bal($cid, 're.current'), '3410 holds the surplus');
        foreach (['inc.entrance', 'inc.interest', 'inc.fees', 'exp.software'] as $key) {
            $this->assertSame(0, $this->bal($cid, $key), $key.' is emptied');
        }
        $entry = SaccoJournalEntry::where('company_id', $cid)->where('idempotency_key', 'close:2025')->firstOrFail();
        $this->assertSame(['year_end', '2026-01-15', '2025-12-31'], [$entry->kind, $entry->entry_date->toDateString(), $entry->value_date->toDateString()]);
        $fy = SaccoFiscalYear::where('company_id', $cid)->where('year', 2025)->firstOrFail();
        $this->assertSame(['closed', 5_500_000], [$fy->status, $fy->net_surplus_minor]);
        $this->assertSame('year_closed', $this->code(fn () => (new Closing())->request($c, 2025, $y['owner'])));

        // the closed year's income statement still shows its income; the position balances and ties to the trial balance
        $is = Figures::income($cid, '2025-01-01', '2025-12-31');
        $this->assertSame([10_080_000, 4_580_000, 5_500_000], [$is['totals']['income'], $is['totals']['expenses'], $is['totals']['net_surplus']]);
        $bs = Figures::position($cid, '2026-01-31');
        $this->assertSame(10_500_000, $bs['totals']['assets']);
        $this->assertSame($bs['totals']['assets'], $bs['totals']['liabilities_and_equity']);
        $this->assertSame([1_000_000, 5_500_000, 0, 4_000_000], [$bs['lines']['share_capital'], $bs['lines']['retained_prior'], $bs['lines']['current_surplus'], $bs['lines']['savings']]);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_the_appropriation_waterfall_empties_3410_and_the_dividend_is_paid_exactly(): void
    {
        $y = $this->year();
        $c = $y['company'];
        $cid = (int) $c->id;
        $this->assertSame('year_not_closed', $this->code(fn () => (new Appropriation())->preview($cid, 2025, [])));
        $this->closeYear($y);
        $data = ['reserve_bp' => 2000, 'ncef_bp' => 100, 'education_bp' => 500, 'stf_minor' => 50_000, 'other_reserves_minor' => 100_000, 'dividend_rate_bp' => 1000];

        // a licensed SACCO is held to the law's limits
        foreach ([['reserve_bp' => 900], ['dividend_rate_bp' => 1100], ['stf_minor' => 50_001], ['ncef_bp' => 50], ['education_bp' => 400]] as $over) {
            $this->assertNotSame('none', $this->code(fn () => (new Appropriation())->request($c, 2025, $over + $data + ['on' => '2026-03-20'], $y['owner'])), json_encode($over));
        }
        $this->assertSame('appropriation_exceeds_surplus', $this->code(fn () => (new Appropriation())->request($c, 2025, ['other_reserves_minor' => 5_000_000] + $data + ['on' => '2026-03-20'], $y['owner'])));

        $pv = (new Appropriation())->preview($cid, 2025, $data + ['on' => '2026-03-20']);
        $this->assertSame(['reserve' => 1_100_000, 'ncef' => 55_000, 'education' => 275_000, 'stf' => 50_000, 'other_reserves' => 100_000, 'dividend' => 100_000, 'retained' => 3_820_000],
            array_column($pv['lines'], 'amount_minor', 'key'));
        $this->assertSame(6_345_000, $pv['core_capital_after_minor'], '1,000,000 shares + 5,500,000 surplus − 55,000 education fund − 100,000 dividend');
        $this->assertSame(6043, $pv['core_capital_ratio_bp_after'], 'of 10,500,000 in assets');
        $this->assertSame([], $pv['warnings']);

        $done = $this->appropriate($y, $data);
        $this->assertSame('executed', $done->status);
        $this->assertSame(0, $this->bal($cid, 're.current'), '3410 nets to zero');
        $this->assertSame([1_100_000, 55_000, 275_000, 50_000, 100_000, 100_000, 3_820_000], [$this->bal($cid, 'reserve.statutory'), $this->bal($cid, 'ncef.payable'),
            $this->bal($cid, 'reserve.education'), $this->bal($cid, 'stf'), $this->bal($cid, 'reserve.general'), $this->bal($cid, 'dividends.payable'), $this->bal($cid, 're.prior')]);
        $this->assertSame('already_appropriated', $this->code(fn () => (new Appropriation())->preview($cid, 2025, $data)));

        // the dividend: no payment without the Registrar's consent; with it, 10 % of each holding, to savings or in cash
        $this->assertSame('registrar_consent_required', $this->code(fn () => (new Dividends())->request($c, 2025, ['pay_to' => 'savings', 'on' => '2026-04-10'], $y['owner'])));
        $consent = ['pay_to' => 'savings', 'on' => '2026-04-10', 'registrar_consent_file_id' => $this->consentFile($cid), 'registrar_consent_on' => '2026-04-02',
            'registrar_consent_ref' => 'MTIC/COOP/2026/17'];
        $a = (new Dividends())->request($c, 2025, $consent, $y['owner']);
        $this->assertSame(2, $a->required_approvals);
        $run = SaccoDividendRun::where('company_id', $cid)->firstOrFail();
        $lines = SaccoDividendLine::where('run_id', $run->id)->orderBy('member_id')->get();
        $this->assertSame([30_000, 30_000, 30_000, 10_000], $lines->pluck('amount_minor')->all());
        $this->assertSame(['savings', 'savings', 'savings', 'cash'], $lines->pluck('paid_to')->all(), 'the fourth member has no savings account');
        $cashBefore = $this->bal($cid, 'cash.office');
        (new SaccoApprovals())->approve($c, (int) $a->id, $y['treasurer']);
        $this->assertSame('executed', (new SaccoApprovals())->approve($c, (int) $a->id, $y['vice'])->status);
        $this->assertSame(0, $this->bal($cid, 'dividends.payable'), '2420 is paid out');
        $this->assertSame([2_030_000, 1_530_000, 530_000], array_map(fn ($s) => $this->balanceOf($s->id), $y['savings']));
        $this->assertSame($cashBefore - 10_000, $this->bal($cid, 'cash.office'));
        $this->assertSame(['paid', 'paid', 'paid', 'paid'], SaccoDividendLine::where('run_id', $run->id)->orderBy('member_id')->pluck('status')->all());
        $entry = SaccoJournalEntry::where('company_id', $cid)->where('idempotency_key', 'div:'.$run->id)->firstOrFail();
        $this->assertSame(['dividend', '2026-04-10'], [$entry->kind, $entry->value_date->toDateString()]);
        $this->assertSame('dividend_paid', $this->code(fn () => (new Dividends())->preview($cid, 2025, $consent)));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
        $bs = Figures::position($cid, '2026-04-30');
        $this->assertSame($bs['totals']['assets'], $bs['totals']['liabilities_and_equity']);
    }

    public function test_an_unlicensed_sacco_goes_ahead_with_warnings(): void
    {
        $y = $this->year(licensed: false);
        $c = $y['company'];
        $cid = (int) $c->id;
        $this->closeYear($y);
        $data = ['reserve_bp' => 500, 'ncef_bp' => 0, 'education_bp' => 0, 'stf_minor' => 60_000, 'dividend_rate_bp' => 1200];
        $pv = (new Appropriation())->preview($cid, 2025, $data + ['on' => '2026-03-20']);
        $this->assertSame([], $pv['refusals']);
        $this->assertSame(['reserve_below_minimum', 'education_fund_below_minimum', 'education_budget_below_minimum', 'stf_above_limit', 'dividend_above_cap'],
            array_column($pv['warnings'], 'code'));
        $this->assertSame('executed', $this->appropriate($y, $data)->status);
        $this->assertSame(0, $this->bal($cid, 're.current'));
        $this->assertSame(120_000, $this->bal($cid, 'dividends.payable'), '12 % of 1,000,000');
        $this->assertNotEmpty(\App\Models\SaccoAppropriation::where('company_id', $cid)->value('warnings'), 'the warnings stay on the appropriation for its report');

        $d = (new Dividends())->preview($cid, 2025, ['pay_to' => 'cash', 'on' => '2026-04-10']);
        $this->assertSame([], $d['refusals']);
        $this->assertSame(['registrar_consent_required', 'dividend_above_cap'], array_column($d['warnings'], 'code'));
        $a = (new Dividends())->request($c, 2025, ['pay_to' => 'cash', 'on' => '2026-04-10'], $y['owner']);
        (new SaccoApprovals())->approve($c, (int) $a->id, $y['treasurer']);
        $this->assertSame('executed', (new SaccoApprovals())->approve($c, (int) $a->id, $y['vice'])->status);
        $this->assertSame(0, $this->bal($cid, 'dividends.payable'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_property_the_dividend_lines_add_up_to_the_pool_on_a_weighted_basis(): void
    {
        $y = $this->year(licensed: false);
        $c = $y['company'];
        $cid = (int) $c->id;
        mt_srand(77);
        // more holdings spread over the year (the year's months are closed: post them as 2025 history first)
        DB::table('sacco_periods')->where('company_id', $cid)->where('year', 2025)->update(['status' => 'open']);
        for ($i = 0; $i < 9; $i++) {
            $m = $this->activeMember($c, $y['owner'], 'Holder', 'P'.$i);
            $month = str_pad((string) mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
            (new ShareService())->purchase($c, ['member_id' => $m->id, 'amount_minor' => 10_000 * mt_rand(1, 9), 'channel' => 'cash', 'value_date' => "2025-{$month}-10"], $y['owner'],
                ['entry_date' => "2025-{$month}-10"]);
        }
        DB::table('sacco_periods')->where('company_id', $cid)->where('year', 2025)->update(['status' => 'closed']);
        foreach ([777, 1000, 333] as $rate) {
            $pool = Dividends::pool($cid, 2025, $rate, 'weighted_monthly');
            $parts = Amount::of($pool['pool_minor'], 'UGX')->allocate(array_map(fn ($m) => $m['basis_minor'], $pool['members']));
            $this->assertSame($pool['pool_minor'], array_sum(array_map(fn ($p) => $p->minor, $parts)), "rate {$rate}: Σ = pool");
            $exact = bcdiv(bcmul((string) $pool['basis_total_minor'], (string) $rate, 0), '120000', 6);
            $this->assertSame((int) bcadd($exact, '0.5', 0), $pool['pool_minor'], 'the pool is the rate on the average holding, rounded once');
        }
    }

    public function test_tenancy_another_saccos_year_is_never_closed_or_appropriated(): void
    {
        $y = $this->year();
        $other = $this->saccoCompany();
        $a = (new Closing())->request($y['company'], 2025, $y['owner'], ['on' => '2026-01-15']);
        $this->assertSame('approval_not_found', $this->code(fn () => (new SaccoApprovals())->approve($other['company'], (int) $a->id, $other['treasurer'])));
        $this->assertSame('year_not_closed', $this->code(fn () => (new Appropriation())->preview((int) $other['company']->id, 2025, [])));
    }
}

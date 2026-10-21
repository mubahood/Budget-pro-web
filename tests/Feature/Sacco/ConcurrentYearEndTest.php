<?php

namespace Tests\Feature\Sacco;

use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoDividendLine;
use App\Models\SaccoInterestRun;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoSetting;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use App\Services\Sacco\Shares\ShareService;
use App\Services\Sacco\YearEnd\Appropriation;
use App\Services\Sacco\YearEnd\Closing;
use App\Services\Sacco\YearEnd\Dividends;
use App\Services\Sacco\YearEnd\SavingsInterest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Akiba Ledger P4 money runs (ADR 0009 §9): real processes (pcntl_fork), real locks, no wrapping transaction. Several
 * processes running the same month's savings interest post one accrual and one credit; three executing one approved
 * dividend run pay each member once; the books stay sound.
 */
class ConcurrentYearEndTest extends TestCase
{
    protected ?int $companyId = null;

    /** Every table a test company here may have rows in, children first. */
    protected const TABLES = ['sacco_return_filings', 'sacco_dormancy_notices', 'sacco_stf_buybacks', 'sacco_dividend_lines', 'sacco_dividend_runs', 'sacco_appropriations',
        'sacco_fiscal_years', 'sacco_interest_run_lines', 'sacco_interest_runs', 'sacco_payouts', 'sacco_transactions', 'sacco_share_accounts', 'sacco_approval_votes',
        'sacco_approvals', 'sacco_journal_lines', 'sacco_journal_entries', 'sacco_account_balances', 'sacco_period_balances', 'sacco_periods', 'sacco_ledger_heads',
        'sacco_savings_accounts', 'sacco_savings_product_versions', 'sacco_savings_products', 'sacco_officers', 'sacco_members', 'sacco_settings', 'message_log',
        'app_notifications', 'number_sequences', 'audit_logs', 'company_members', 'financial_categories'];

    protected function tearDown(): void
    {
        if ($this->companyId) {
            DB::reconnect();
            $cid = $this->companyId;
            DB::statement('SET @sacco_ledger_purge = 1');
            foreach (self::TABLES as $table) {
                DB::table($table)->where('company_id', $cid)->delete();
            }
            DB::table('sacco_ledger_accounts')->where('company_id', $cid)->whereNotNull('parent_id')->delete();
            DB::table('sacco_ledger_accounts')->where('company_id', $cid)->delete();
            DB::statement('SET @sacco_ledger_purge = 0');
            DB::table((new User())->getTable())->where('company_id', $cid)->delete();
            DB::table('companies')->where('id', $cid)->delete();
        }
        parent::tearDown();
    }

    /** @return list<string> each process's outcome, sorted */
    protected function race(int $n, callable $work): array
    {
        $dir = sys_get_temp_dir().'/sacco-ye-race-'.uniqid();
        mkdir($dir);
        DB::disconnect();
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                DB::reconnect();
                $out = 'ok';
                try {
                    $work($i);
                } catch (\App\Exceptions\BusinessRuleException $e) {
                    $out = $e->errorCode();
                } catch (\Throwable $e) {
                    $out = 'error: '.$e->getMessage();
                }
                file_put_contents("{$dir}/{$i}", $out);
                DB::disconnect();
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();
        $results = [];
        for ($i = 0; $i < $n; $i++) {
            $results[] = (string) @file_get_contents("{$dir}/{$i}");
            @unlink("{$dir}/{$i}");
        }
        @rmdir($dir);
        sort($results);

        return $results;
    }

    /** @return array{0: Company, 1: User} committed books: an office SACCO with months open from two months back */
    protected function books(bool $licensed = true): array
    {
        $company = Company::factory()->create(['name' => 'Year-end Race SACCO', 'status' => 'Active', 'currency' => 'UGX', 'enabled_modules' => ['sacco']]);
        $this->companyId = $cid = (int) $company->id;
        $owner = User::factory()->create(['company_id' => $cid, 'email' => 'yerace_'.uniqid().'@example.com', 'password' => bcrypt('secret123')]);
        $company->owner_id = $owner->id;
        $company->saveQuietly();
        SaccoSetting::create(['company_id' => $cid, 'mode' => 'office', 'legal_name' => 'Year-end Race SACCO', 'short_name' => 'Race', 'currency' => 'UGX',
            'withdrawal_approval_above_minor' => 100_000_000, 'offline_receipt_max_minor' => 5_000_000, 'share_par_minor' => 10_000, 'min_shares' => 1,
            'max_holding_bp' => 3333, 'entrance_fee_minor' => 0, 'receipts_channel' => 'none', 'dividend_cap_bp' => 1000, 'reserve_min_bp' => 1000, 'education_fund_bp' => 100,
            'dormancy_months' => 24, 'licence_no' => $licensed ? 'UMRA/RACE/1' : null]);
        (new ChartOfAccounts())->seed($cid, 'office');
        (new PeriodService())->ensureAhead($cid, now()->subMonthsNoOverflow(2), 4);
        (new SavingsProducts())->ensureDefaults($company->fresh());

        return [$company->fresh(), $owner];
    }

    protected function member(Company $company, string $no): SaccoMember
    {
        return SaccoMember::create(['company_id' => $company->id, 'member_no' => $no, 'first_name' => 'Race', 'last_name' => 'Member '.$no, 'joined_on' => now()->subYear()->toDateString(),
            'status' => 'active', 'kyc_status' => 'verified', 'uuid' => (string) Str::uuid()]);
    }

    protected function savings(Company $company, SaccoMember $m, SaccoSavingsProduct $p, int $minor, string $on): SaccoSavingsAccount
    {
        $a = (new SavingsAccounts())->open($company, (int) $m->id, (int) $p->id, null, ['opened_on' => $on]);
        (new SaccoTransactions())->record($company, ['kind' => 'deposit', 'channel' => 'cash', 'member_id' => $m->id, 'amount_minor' => $minor, 'savings_account_id' => $a->id,
            'value_date' => $on], null, ['entry_date' => $on]);

        return $a;
    }

    public function test_four_processes_running_one_months_interest_post_it_once(): void
    {
        [$company] = $this->books();
        $cid = (int) $company->id;
        $p = (new SavingsProducts())->create($company, ['code' => 'INT', 'name' => 'Interest savings', 'kind' => 'voluntary', 'interest_rate_bp' => 1000,
            'interest_basis' => 'daily_balance', 'interest_posting' => 'monthly'], null);
        $start = now()->subMonthsNoOverflow(2)->startOfMonth();
        foreach (range(1, 5) as $i) {
            $this->savings($company, $this->member($company, 'M-97000'.$i), $p, 1_000_000 * $i, $start->toDateString());
        }
        $month = $start->format('Y-m');
        $entries = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->count();

        $results = $this->race(4, fn () => (new SavingsInterest())->run($cid, $month));

        $this->assertSame(['ok', 'ok', 'ok', 'ok'], $results);
        $this->assertSame(1, SaccoInterestRun::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'accrual')->count());
        $this->assertSame(1, SaccoInterestRun::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'credit')->count());
        $this->assertSame($entries + 2, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->count(), 'one accrual entry and one credit entry');
        $this->assertSame(0, (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, 'savings.interest_payable'))->value('balance_minor'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_three_processes_executing_one_dividend_run_pay_it_once(): void
    {
        [$company, $owner] = $this->books(licensed: false); // no Registrar's consent on file: a warning for an unlicensed SACCO
        $cid = (int) $company->id;
        (new PeriodService())->ensureAhead($cid, \Illuminate\Support\Carbon::parse((now()->year - 1).'-01-01'), 14);
        $year = now()->year - 1;
        $ord = SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $cid)->where('code', 'ORD')->firstOrFail();
        foreach ([400_000, 350_000, 250_000] as $i => $shares) {
            $m = $this->member($company, 'M-98000'.$i);
            $this->savings($company, $m, $ord, 100_000, "{$year}-02-01");
            (new ShareService())->purchase($company, ['member_id' => $m->id, 'amount_minor' => $shares, 'channel' => 'cash', 'value_date' => "{$year}-03-01"], $owner, ['entry_date' => "{$year}-03-01"]);
        }
        $income = \App\Support\Sacco\Amount::of(5_000_000, 'UGX');
        (new \App\Services\Sacco\Ledger\PostingService())->post(\App\Services\Sacco\Ledger\JournalDraft::make($cid, 'manual', 'race:income')
            ->debit(ChartOfAccounts::id($cid, 'bank.main'), $income)->credit(ChartOfAccounts::id($cid, 'inc.interest'), $income)->dated("{$year}-06-30", "{$year}-06-30"));
        foreach (\App\Models\SaccoPeriod::withoutGlobalScopes()->where('company_id', $cid)->where('year', $year)->orderBy('month')->get() as $p) {
            (new PeriodService())->close($cid, (int) $p->id);
        }
        $approve = function (SaccoApproval $a) use ($company, $owner) {
            $a->forceFill(['status' => 'approved', 'decided_at' => now()])->save(); // the two officers' approvals, outside the race

            return (new SaccoApprovals())->execute($company, (int) $a->id, $owner);
        };
        $approve((new Closing())->request($company, $year, $owner));
        $approve((new Appropriation())->request($company, $year, ['dividend_rate_bp' => 1000], $owner));
        $run = (new Dividends())->request($company, $year, ['pay_to' => 'savings'], $owner);
        $run->forceFill(['status' => 'approved', 'decided_at' => now()])->save();

        $results = $this->race(3, fn () => (new SaccoApprovals())->execute(Company::find($cid), (int) $run->id, User::withoutGlobalScopes()->find($owner->id)));

        $this->assertSame(['ok', 'ok', 'ok'], $results, 'every process sees the one execution');
        $this->assertSame(1, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'dividend')->count());
        $this->assertSame(['paid', 'paid', 'paid'], SaccoDividendLine::withoutGlobalScopes()->where('company_id', $cid)->orderBy('member_id')->pluck('status')->all());
        $this->assertSame([40_000, 35_000, 25_000], SaccoDividendLine::withoutGlobalScopes()->where('company_id', $cid)->orderBy('member_id')->pluck('amount_minor')->all());
        $this->assertSame(0, (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, 'dividends.payable'))->value('balance_minor'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }
}

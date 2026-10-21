<?php

namespace Tests\Feature\Sacco;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\SaccoSetting;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Akiba Ledger P1.12 (ADR 0009 §9, 04 §5.2): real processes (pcntl_fork) and real row locks, no wrapping
 * transaction. Two withdrawals of 60 % of one balance at the same moment: exactly one is paid, the other is refused
 * `insufficient_available`, and the books verify. Five processes sending the same deposit uuid post it once. Two
 * share purchases that would each fit under the one-third cap but not together: exactly one passes (P1.11).
 */
class ConcurrentWithdrawalsTest extends TestCase
{
    private ?int $companyId = null;

    protected function tearDown(): void
    {
        if ($this->companyId) {
            DB::reconnect();
            $cid = $this->companyId;
            DB::statement('SET @sacco_ledger_purge = 1');
            foreach (['sacco_transactions', 'sacco_share_accounts', 'sacco_approval_votes', 'sacco_approvals', 'sacco_journal_lines', 'sacco_journal_entries', 'sacco_account_balances', 'sacco_period_balances',
                'sacco_periods', 'sacco_ledger_heads', 'sacco_savings_accounts', 'sacco_savings_product_versions', 'sacco_savings_products', 'sacco_members', 'sacco_settings',
                'number_sequences', 'audit_logs', 'company_members', 'financial_categories'] as $table) {
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

    /** @return list<string> each child's outcome: ok or the refusal code */
    private function race(int $n, callable $work): array
    {
        $dir = sys_get_temp_dir().'/sacco-race-'.uniqid();
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

    /** @return array{0: Company, 1: User, 2: \App\Models\SaccoSavingsAccount} committed books: an office SACCO, its owner and one member's savings account */
    private function books(): array
    {
        $company = Company::factory()->create(['name' => 'Race SACCO', 'status' => 'Active', 'currency' => 'UGX', 'enabled_modules' => ['sacco']]);
        $this->companyId = $cid = (int) $company->id;
        $owner = User::factory()->create(['company_id' => $cid, 'email' => 'race_'.uniqid().'@example.com', 'password' => bcrypt('secret123')]);
        $company->owner_id = $owner->id;
        $company->saveQuietly();
        SaccoSetting::create(['company_id' => $cid, 'mode' => 'office', 'legal_name' => 'Race SACCO', 'short_name' => 'Race', 'currency' => 'UGX',
            'withdrawal_approval_above_minor' => 100_000_000, 'offline_receipt_max_minor' => 5_000_000, 'share_par_minor' => 10_000, 'min_shares' => 1,
            'max_holding_bp' => 3333, 'entrance_fee_minor' => 0, 'receipts_channel' => 'none', 'dividend_cap_bp' => 1000, 'reserve_min_bp' => 1000, 'education_fund_bp' => 100, 'dormancy_months' => 24]);
        (new ChartOfAccounts())->seed($cid, 'office');
        (new PeriodService())->ensureAhead($cid, now(), 2);
        (new SavingsProducts())->ensureDefaults($company->fresh());
        $member = SaccoMember::create(['company_id' => $cid, 'member_no' => 'M-900001', 'first_name' => 'Race', 'last_name' => 'Member', 'joined_on' => now()->toDateString(),
            'status' => 'active', 'kyc_status' => 'verified']);
        $product = \App\Models\SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $cid)->where('code', 'ORD')->firstOrFail();
        $account = (new SavingsAccounts())->open($company->fresh(), (int) $member->id, (int) $product->id, null);

        return [$company->fresh(), $owner, $account];
    }

    public function test_two_share_purchases_racing_at_the_cap_let_only_one_through(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        [$company, $owner] = $this->books();
        $cid = (int) $company->id;
        $ids = [];
        foreach (['A', 'B', 'C', 'D'] as $i => $name) {
            $m = SaccoMember::create(['company_id' => $cid, 'member_no' => 'M-90010'.$i, 'first_name' => $name, 'last_name' => 'Holder', 'joined_on' => now()->toDateString(),
                'status' => 'active', 'kyc_status' => 'verified']);
            (new \App\Services\Sacco\Shares\ShareService())->purchase($company, ['member_id' => $m->id, 'shares_count' => 10], null);
            $ids[] = (int) $m->id;
        }
        $results = $this->race(2, fn (int $i) => (new \App\Services\Sacco\Shares\ShareService())->purchase(Company::find($cid), ['member_id' => $ids[0], 'shares_count' => 3], null));
        $this->assertSame(['ok', 'share_cap_exceeded'], $results, '13 of 43 fits; 16 of 46 does not');
        $this->assertSame(13, (int) DB::table('sacco_share_accounts')->where('company_id', $cid)->where('member_id', $ids[0])->value('shares_count'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_two_withdrawals_of_sixty_percent_race_and_exactly_one_is_paid(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        [$company, $owner, $account] = $this->books();
        $cid = (int) $company->id;
        $svc = new SaccoTransactions();
        $svc->record($company->fresh(), ['kind' => 'deposit', 'savings_account_id' => $account->id, 'amount_minor' => 1_000_000], null);

        $uuid = (string) Str::uuid();
        $same = $this->race(5, fn () => (new SaccoTransactions())->record(Company::find($cid), ['kind' => 'deposit', 'uuid' => $uuid, 'savings_account_id' => $account->id,
            'amount_minor' => 50_000], null));
        $this->assertSame(['ok', 'ok', 'ok', 'ok', 'ok'], $same, 'every retry gets the one receipt back');
        $this->assertSame(1, SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->count());

        $results = $this->race(2, fn () => (new SaccoTransactions())->record(Company::find($cid), ['kind' => 'withdrawal', 'savings_account_id' => $account->id,
            'amount_minor' => 630_000], User::withoutGlobalScopes()->find($owner->id)));
        $this->assertSame(['insufficient_available', 'ok'], $results);
        $this->assertSame(1_050_000 - 630_000, (int) DB::table('sacco_savings_accounts')->where('id', $account->id)->value('balance_minor'));
        $this->assertSame(1, SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'withdrawal')->count());
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }
}

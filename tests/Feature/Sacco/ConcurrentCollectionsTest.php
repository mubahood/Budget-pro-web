<?php

namespace Tests\Feature\Sacco;

use App\Models\Company;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoSetting;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Payments\SaccoCollections;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Akiba Ledger P3.1 (ADR 0009 §9): real processes (pcntl_fork), real locks, no wrapping transaction. Six processes
 * settling one confirmed collection at once (the webhook, the poll and the app's status checks arriving together)
 * record one receipt and one posting, and the books stay sound.
 */
class ConcurrentCollectionsTest extends TestCase
{
    private ?int $companyId = null;

    protected function tearDown(): void
    {
        if ($this->companyId) {
            DB::reconnect();
            $cid = $this->companyId;
            DB::statement('SET @sacco_ledger_purge = 1');
            foreach (['momo_requests', 'sacco_transactions', 'sacco_approvals', 'sacco_journal_lines', 'sacco_journal_entries', 'sacco_account_balances', 'sacco_period_balances', 'sacco_periods',
                'sacco_ledger_heads', 'sacco_savings_accounts', 'sacco_savings_product_versions', 'sacco_savings_products', 'sacco_members', 'sacco_settings', 'message_log',
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

    public function test_six_processes_settling_one_payment_post_it_once(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        config(['sacco.collections.driver' => 'simulated', 'sacco.collections.simulated_approve_after' => 0]);
        $company = Company::factory()->create(['name' => 'Collect Race SACCO', 'status' => 'Active', 'currency' => 'UGX', 'enabled_modules' => ['sacco']]);
        $this->companyId = $cid = (int) $company->id;
        $owner = User::factory()->create(['company_id' => $cid, 'email' => 'crace_'.uniqid().'@example.com', 'password' => bcrypt('secret123')]);
        $company->owner_id = $owner->id;
        $company->saveQuietly();
        SaccoSetting::create(['company_id' => $cid, 'mode' => 'office', 'legal_name' => 'Collect Race SACCO', 'short_name' => 'Race', 'currency' => 'UGX',
            'withdrawal_approval_above_minor' => 100_000_000, 'offline_receipt_max_minor' => 5_000_000, 'share_par_minor' => 10_000, 'min_shares' => 1,
            'max_holding_bp' => 3333, 'entrance_fee_minor' => 0, 'receipts_channel' => 'none', 'dividend_cap_bp' => 1000, 'reserve_min_bp' => 1000, 'education_fund_bp' => 100, 'dormancy_months' => 24]);
        (new ChartOfAccounts())->seed($cid, 'office');
        (new PeriodService())->ensureAhead($cid, now()->subMonth(), 3);
        (new SavingsProducts())->ensureDefaults($company->fresh());
        $member = SaccoMember::create(['company_id' => $cid, 'member_no' => 'M-920001', 'first_name' => 'Race', 'last_name' => 'Payer', 'joined_on' => now()->subYear()->toDateString(),
            'status' => 'active', 'kyc_status' => 'verified', 'uuid' => (string) Str::uuid(), 'phone_e164' => '+256772000111']);
        $account = (new SavingsAccounts())->open($company->fresh(), (int) $member->id, (int) SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $cid)->where('code', 'ORD')->value('id'), null);
        $req = (new SaccoCollections())->request($company->fresh(), ['purpose' => 'sacco_deposit', 'savings_account_id' => $account->id, 'amount_minor' => 75_000], null, $member);

        $dir = sys_get_temp_dir().'/sacco-collect-race-'.uniqid();
        mkdir($dir);
        DB::disconnect();
        $pids = [];
        for ($i = 0; $i < 6; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                DB::reconnect();
                $out = 'ok';
                try {
                    (new SaccoCollections())->settle((string) $req->tx_ref);
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
        for ($i = 0; $i < 6; $i++) {
            $results[] = (string) @file_get_contents("{$dir}/{$i}");
            @unlink("{$dir}/{$i}");
        }
        @rmdir($dir);

        $this->assertSame(array_fill(0, 6, 'ok'), $results);
        $this->assertSame('successful', DB::table('momo_requests')->where('id', $req->id)->value('status'));
        $this->assertSame(1, SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('channel', 'momo')->count());
        $this->assertSame(1, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'deposit')->count());
        $this->assertSame(75_000, (int) DB::table('sacco_savings_accounts')->where('id', $account->id)->value('balance_minor'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }
}

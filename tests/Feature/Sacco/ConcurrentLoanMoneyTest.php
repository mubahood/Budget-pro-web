<?php

namespace Tests\Feature\Sacco;

use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoLoanRepayment;
use App\Models\SaccoMember;
use App\Models\SaccoSetting;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Loans\LoanProducts;
use App\Services\Sacco\Loans\Repayments;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsProducts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Akiba Ledger P2.9–P2.11 (ADR 0009 §9): real processes (pcntl_fork), real locks, no wrapping transaction. One
 * approved disbursement executed by three processes pays out once; a repayment racing the interest accrual on the
 * same due date ends with both posted once and the books sound either way (RepaymentVsAccrualTest); five processes
 * pushing one repayment uuid post it once.
 */
class ConcurrentLoanMoneyTest extends TestCase
{
    private ?int $companyId = null;

    protected function tearDown(): void
    {
        if ($this->companyId) {
            DB::reconnect();
            $cid = $this->companyId;
            DB::statement('SET @sacco_ledger_purge = 1');
            foreach (['sacco_provision_runs', 'sacco_payouts', 'sacco_member_exits', 'sacco_loan_votes', 'sacco_loan_documents', 'sacco_loan_collateral', 'sacco_loan_repayments', 'sacco_loan_changes', 'sacco_loan_schedule_lines', 'sacco_loan_guarantors', 'sacco_loans', 'sacco_loan_products', 'sacco_transactions', 'sacco_share_accounts',
                'sacco_approval_votes', 'sacco_approvals', 'sacco_journal_lines', 'sacco_journal_entries', 'sacco_account_balances', 'sacco_period_balances', 'sacco_periods',
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

    /** @return list<string> */
    private function race(int $n, callable $work): array
    {
        $dir = sys_get_temp_dir().'/sacco-loan-race-'.uniqid();
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

    /** @return array{0: Company, 1: User, 2: SaccoLoan} committed books with a signed loan and an approved payout request */
    private function books(): array
    {
        $company = Company::factory()->create(['name' => 'Loan Race SACCO', 'status' => 'Active', 'currency' => 'UGX', 'enabled_modules' => ['sacco']]);
        $this->companyId = $cid = (int) $company->id;
        $owner = User::factory()->create(['company_id' => $cid, 'email' => 'lrace_'.uniqid().'@example.com', 'password' => bcrypt('secret123')]);
        $company->owner_id = $owner->id;
        $company->saveQuietly();
        SaccoSetting::create(['company_id' => $cid, 'mode' => 'office', 'legal_name' => 'Loan Race SACCO', 'short_name' => 'Race', 'currency' => 'UGX',
            'withdrawal_approval_above_minor' => 100_000_000, 'offline_receipt_max_minor' => 5_000_000, 'share_par_minor' => 10_000, 'min_shares' => 1,
            'max_holding_bp' => 3333, 'entrance_fee_minor' => 0, 'receipts_channel' => 'none', 'dividend_cap_bp' => 1000, 'reserve_min_bp' => 1000, 'education_fund_bp' => 100, 'dormancy_months' => 24]);
        (new ChartOfAccounts())->seed($cid, 'office');
        (new PeriodService())->ensureAhead($cid, now()->subMonths(2), 4);
        (new SavingsProducts())->ensureDefaults($company->fresh());
        (new LoanProducts())->ensureDefaults($company->fresh());
        $member = SaccoMember::create(['company_id' => $cid, 'member_no' => 'M-910001', 'first_name' => 'Race', 'last_name' => 'Borrower', 'joined_on' => now()->subYear()->toDateString(),
            'status' => 'active', 'kyc_status' => 'verified', 'uuid' => (string) Str::uuid()]);
        (new SaccoTransactions())->record($company->fresh(), ['kind' => 'deposit', 'channel' => 'cash', 'member_id' => $member->id, 'amount_minor' => 50_000_000,
            'savings_account_id' => (new \App\Services\Sacco\Savings\SavingsAccounts())->open($company->fresh(), (int) $member->id,
                (int) \App\Models\SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $cid)->where('code', 'ORD')->value('id'), null)->id], null);
        $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $cid)->where('code', 'ORD')->firstOrFail();
        $signed = now()->subDays(40)->startOfDay();
        $loan = SaccoLoan::create(['company_id' => $cid, 'loan_no' => 'LN-RACE-1', 'member_id' => $member->id, 'product_id' => $product->id, 'status' => 'signed', 'applied_minor' => 600_000,
            'approved_minor' => 600_000, 'currency' => 'UGX', 'term' => 6, 'terms' => $product->terms(6)->toArray(), 'uuid' => (string) Str::uuid(), 'signed_on' => $signed->toDateString(),
            'cooling_off_ends_on' => $signed->copy()->addDays(7)->toDateString(), 'disbursement_channel' => 'cash']);

        return [$company->fresh(), $owner, $loan];
    }

    public function test_one_payout_from_three_executions_and_a_repayment_racing_the_accrual(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        [$company, $owner, $loan] = $this->books();
        $cid = (int) $company->id;
        $disburseOn = now()->subDays(40)->toDateString();
        $approval = (new SaccoApprovals())->request($company, 'loan_disbursement', ['loan_id' => (int) $loan->id, 'loan_no' => $loan->loan_no, 'member_id' => (int) $loan->member_id,
            'amount_minor' => 600_000, 'channel' => 'cash', 'savings_account_id' => null, 'momo_phone_e164' => null, 'bank_reference' => null, 'disburse_on' => $disburseOn, 'first_due_on' => null], $owner);
        $approval->forceFill(['status' => 'approved', 'decided_at' => now()])->save(); // the second officer's approval, outside the race

        $results = $this->race(3, fn () => (new SaccoApprovals())->execute(Company::find($cid), (int) $approval->id, User::withoutGlobalScopes()->find($owner->id)));
        $this->assertSame(['ok', 'ok', 'ok'], $results, 'every process sees the one execution');
        $this->assertSame(1, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'loan_disbursement')->count());
        $this->assertSame('executed', SaccoApproval::withoutGlobalScopes()->find($approval->id)->status);
        $loan = SaccoLoan::withoutGlobalScopes()->find($loan->id);
        $this->assertSame(['active', 600_000], [$loan->status, $loan->principal_outstanding_minor]);

        // The first due date has passed: the accrual and a repayment race for the loan.
        $due = $loan->first_due_on->toDateString();
        $results = $this->race(2, function (int $i) use ($cid, $loan, $due) {
            if ($i === 0) {
                (new LoanJobs())->run($cid, $due);
            } else {
                (new Repayments())->record(Company::find($cid), (int) $loan->id, ['amount_minor' => 120_000, 'channel' => 'cash', 'value_date' => $due], null);
            }
        });
        $this->assertSame(['ok', 'ok'], $results);
        $this->assertSame(1, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('idempotency_key', 'int:'.$loan->id.':1:1')->count());
        $this->assertSame(1, SaccoLoanRepayment::withoutGlobalScopes()->where('loan_id', $loan->id)->count());
        $loan = SaccoLoan::withoutGlobalScopes()->find($loan->id);
        $rep = SaccoLoanRepayment::withoutGlobalScopes()->where('loan_id', $loan->id)->first();
        $this->assertSame(600_000 + 12_000 - 120_000, $loan->principal_outstanding_minor + $loan->interest_due_minor, 'interest 12,000 charged once; either order adds up');
        $this->assertSame(120_000, $rep->interest_minor + $rep->principal_minor);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));

        $uuid = (string) Str::uuid();
        $results = $this->race(5, fn () => (new Repayments())->record(Company::find($cid), (int) $loan->id, ['uuid' => $uuid, 'amount_minor' => 10_000, 'channel' => 'cash'], null));
        $this->assertSame(['ok', 'ok', 'ok', 'ok', 'ok'], $results);
        $this->assertSame(1, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('idempotency_key', 'txn:'.$uuid)->count());
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }
}

<?php

namespace Tests\Feature\Sacco;

use App\Models\Company;
use App\Models\SaccoJournalEntry;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Ledger\PostingService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoLedger;
use Tests\TestCase;

/**
 * Akiba Ledger P1.5 (ADR 0009 §9): real processes (pcntl_fork), real row locks, no wrapping transaction (the
 * ConcurrentPledgePaymentsTest pattern). Six processes posting the same key give one entry; eight posting their own
 * keys give eight, in one unbroken hash chain, and the books verify. Rows are removed in tearDown, past the
 * journal triggers with the purge flag only a whole test or demo company may set.
 */
class ConcurrentPostingSameKeyTest extends TestCase
{
    use MakesSaccoLedger;

    private ?int $companyId = null;

    protected function tearDown(): void
    {
        if ($this->companyId) {
            DB::reconnect();
            DB::statement('SET @sacco_ledger_purge = 1');
            foreach (['sacco_journal_lines', 'sacco_journal_entries', 'sacco_account_balances', 'sacco_period_balances', 'sacco_periods', 'sacco_ledger_heads',
                'number_sequences', 'audit_logs', 'company_members', 'financial_categories'] as $table) {
                DB::table($table)->where('company_id', $this->companyId)->delete();
            }
            DB::table('sacco_ledger_accounts')->where('company_id', $this->companyId)->whereNotNull('parent_id')->delete();
            DB::table('sacco_ledger_accounts')->where('company_id', $this->companyId)->delete();
            DB::statement('SET @sacco_ledger_purge = 0');
            DB::table((new User())->getTable())->where('company_id', $this->companyId)->delete();
            DB::table('companies')->where('id', $this->companyId)->delete();
        }
        parent::tearDown();
    }

    /** @return array{ok: int, errors: int} */
    private function race(int $n, callable $work): array
    {
        DB::disconnect();
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                DB::reconnect();
                $code = 2;
                try {
                    $work($i);
                    $code = 0;
                } catch (\Throwable $e) {
                    fwrite(STDERR, 'child error: '.get_class($e).' '.$e->getMessage()."\n");
                }
                DB::disconnect();
                exit($code);
            }
            $pids[] = $pid;
        }
        $out = ['ok' => 0, 'errors' => 0];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            pcntl_wexitstatus($status) === 0 ? $out['ok']++ : $out['errors']++;
        }
        DB::reconnect();

        return $out;
    }

    public function test_the_same_key_from_six_processes_posts_once_and_eight_keys_chain_cleanly(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        $company = Company::factory()->create(['name' => 'Concurrent SACCO', 'status' => 'Active', 'currency' => 'UGX']);
        $this->companyId = (int) $company->id;
        (new ChartOfAccounts())->seed($this->companyId, 'office');
        (new PeriodService())->ensureAhead($this->companyId, now(), 2);
        $cid = $this->companyId;

        $r = $this->race(6, fn () => (new PostingService())->post($this->simple($cid, 'txn:same', 25000)));
        $this->assertSame(['ok' => 6, 'errors' => 0], $r, 'every process gets the one entry back');
        $this->assertSame(1, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->count());

        $r = $this->race(8, fn (int $i) => (new PostingService())->post($this->simple($cid, "txn:own{$i}", 1000 * ($i + 1))));
        $this->assertSame(['ok' => 8, 'errors' => 0], $r);
        $this->assertSame(9, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame(9, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->distinct()->count('entry_no'), 'entry numbers are unique');
        $this->assertSame(25000 + 36000, (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, 'cash.office'))->value('balance_minor'));
        $result = (new LedgerVerifier())->verify($cid);
        $this->assertSame('ok', $result['checks']['chain']['status'], $result['checks']['chain']['detail']);
        $this->assertSame([], LedgerVerifier::failed($result));
    }
}

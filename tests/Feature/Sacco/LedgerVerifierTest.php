<?php

namespace Tests\Feature\Sacco;

use App\Console\Commands\SaccoLedgerVerify;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoLedger;
use Tests\TestCase;

/**
 * Akiba Ledger P1.5 (ADR 0009 §6, §7): what the verifier catches. A change made straight in the database (here past
 * the triggers, as someone with database access could) breaks the hash chain from that entry on, and the cached
 * balance no longer matches its lines; the nightly command tells the owner, the platform and System health.
 */
class LedgerVerifierTest extends TestCase
{
    use DatabaseTransactions, MakesSaccoLedger;

    protected function tearDown(): void
    {
        DB::statement('SET @sacco_ledger_purge = 0');
        parent::tearDown();
    }

    public function test_sound_books_verify_and_a_raw_edit_breaks_the_chain(): void
    {
        [$company] = $this->saccoBooks();
        $cid = (int) $company->id;
        $entries = [];
        foreach ([10000, 20000, 30000] as $i => $m) {
            $entries[] = (new PostingService())->post($this->simple($cid, "txn:v{$i}", $m));
        }
        $ok = (new LedgerVerifier())->verify($cid);
        $this->assertTrue($ok['ok']);
        $this->assertSame('ok', $ok['status']);
        $this->assertSame(LedgerVerifier::CHECKS, array_keys($ok['checks']));
        $this->assertSame(LedgerVerifier::triggers(), $ok['triggers']);

        DB::statement('SET @sacco_ledger_purge = 1'); // what a trigger would stop; the chain still sees it
        DB::table('sacco_journal_lines')->where('journal_entry_id', $entries[1]->id)->where('line_no', 1)->update(['debit_minor' => 2000]);
        DB::table('sacco_journal_lines')->where('journal_entry_id', $entries[1]->id)->where('line_no', 2)->update(['credit_minor' => 2000]);
        DB::statement('SET @sacco_ledger_purge = 0');

        $bad = (new LedgerVerifier())->verify($cid);
        $this->assertFalse($bad['ok']);
        $this->assertSame($entries[1]->entry_no, $bad['first_broken_entry']);
        $this->assertSame(['balanced', 'account_caches', 'chain'], LedgerVerifier::failed($bad));
        $this->assertStringContainsString($entries[1]->entry_no, $bad['checks']['chain']['detail']);
    }

    public function test_a_removed_entry_and_a_tampered_cache_are_caught(): void
    {
        [$company] = $this->saccoBooks();
        $cid = (int) $company->id;
        (new PostingService())->post($this->simple($cid, 'txn:a', 5000));
        $b = (new PostingService())->post($this->simple($cid, 'txn:b', 7000));
        DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, 'cash.office'))->update(['balance_minor' => 1]);
        $this->assertSame(['account_caches'], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));

        DB::statement('SET @sacco_ledger_purge = 1');
        DB::table('sacco_journal_lines')->where('journal_entry_id', $b->id)->delete();
        DB::table('sacco_journal_entries')->where('id', $b->id)->delete();
        DB::statement('SET @sacco_ledger_purge = 0');
        $r = (new LedgerVerifier())->verify($cid);
        $this->assertContains('chain', LedgerVerifier::failed($r), 'the head still counts the removed entry');
    }

    public function test_the_nightly_command_reports_to_the_owner_the_platform_and_system_health(): void
    {
        [$good] = $this->saccoBooks();
        [$bad] = $this->saccoBooks();
        (new PostingService())->post($this->simple((int) $good->id, 'txn:g', 1000));
        (new PostingService())->post($this->simple((int) $bad->id, 'txn:b', 1000));
        DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id((int) $bad->id, 'inc.entrance'))->update(['balance_minor' => 999]);

        config(['app.track_errors_in_tests' => true]);
        $this->artisan('sacco:ledger-verify')->assertFailed();
        $summary = Cache::get(SaccoLedgerVerify::CACHE_KEY);
        $this->assertContains((int) $bad->id, array_column($summary['failed'], 'company_id'));
        $this->assertNotContains((int) $good->id, array_column($summary['failed'], 'company_id'));
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $bad->id)->where('type', SaccoLedgerVerify::NOTICE)->count());
        $this->assertSame(0, DB::table('app_notifications')->where('company_id', $good->id)->where('type', SaccoLedgerVerify::NOTICE)->count());
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $bad->id)->where('action', 'sacco.ledger.verify_failed')->exists());
        $this->assertTrue(DB::table('error_events')->where('message', 'like', '%company '.$bad->id.'%')->exists());
        $this->artisan('sacco:ledger-verify', ['--company' => $good->id])->assertSuccessful();
        $this->assertArrayHasKey('sacco_ledger', app(\App\Services\Ops\HealthService::class)->snapshot());
    }
}

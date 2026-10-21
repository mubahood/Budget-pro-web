<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoJournalLine;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoPeriod;
use App\Models\SaccoPeriodBalance;
use App\Scopes\CompanyScope;
use App\Support\SchemaIntegrity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Akiba Ledger P1.4 (ADR 0009 §5): the ledger's tables, their constraints and their guards. Posted journal rows
 * and closing snapshots refuse to change through the model and, where the triggers are installed, in the
 * database too; every table is per company; the migrations run again without harm.
 */
class LedgerSchemaTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRATIONS = ['2026_10_21_600001_create_sacco_ledger_accounts_and_heads', '2026_10_21_600002_create_sacco_periods',
        '2026_10_21_600003_create_sacco_journal', '2026_10_21_600004_create_sacco_account_balances'];

    /** @return array{0: Company, 1: SaccoJournalEntry, 2: SaccoJournalLine} a raw entry (the posting service comes in P1.5) */
    private function rawEntry(): array
    {
        $company = Company::factory()->create(['name' => 'Schema SACCO', 'status' => 'Active']);
        $account = SaccoLedgerAccount::create(['company_id' => $company->id, 'code' => '1010', 'name' => 'Cash', 'type' => 'asset', 'normal_side' => 'debit']);
        $period = SaccoPeriod::create(['company_id' => $company->id, 'year' => 2026, 'month' => 10, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $entry = SaccoJournalEntry::create(['company_id' => $company->id, 'entry_no' => 'JE-2026-000001', 'entry_date' => '2026-10-21', 'value_date' => '2026-10-21',
            'period_id' => $period->id, 'kind' => 'manual', 'idempotency_key' => 'test:1', 'payload_hash' => str_repeat('a', 64), 'total_minor' => 100,
            'currency' => 'UGX', 'prev_hash' => str_repeat('0', 64), 'hash' => str_repeat('b', 64)]);
        $line = SaccoJournalLine::create(['company_id' => $company->id, 'journal_entry_id' => $entry->id, 'line_no' => 1, 'ledger_account_id' => $account->id,
            'debit_minor' => 100, 'credit_minor' => 0, 'entry_date' => '2026-10-21', 'value_date' => '2026-10-21']);

        return [$company, $entry, $line];
    }

    private function assertImmutable(callable $fn): void
    {
        try {
            $fn();
            $this->fail('the ledger must refuse this');
        } catch (BusinessRuleException $e) {
            $this->assertSame('ledger_immutable', $e->errorCode());
        }
    }

    public function test_posted_rows_refuse_updates_and_deletes_through_the_model(): void
    {
        [, $entry, $line] = $this->rawEntry();
        $this->assertImmutable(fn () => $entry->update(['memo' => 'edited']));
        $this->assertImmutable(fn () => $entry->delete());
        $this->assertImmutable(fn () => $line->update(['debit_minor' => 1]));
        $this->assertImmutable(fn () => $line->delete());
        $snapshot = SaccoPeriodBalance::create(['company_id' => $entry->company_id, 'period_id' => $entry->period_id, 'ledger_account_id' => $line->ledger_account_id,
            'opening_minor' => 0, 'debits_minor' => 100, 'credits_minor' => 0, 'closing_minor' => 100]);
        $this->assertImmutable(fn () => $snapshot->update(['closing_minor' => 1]));
        $this->assertImmutable(fn () => $snapshot->delete());
        $this->assertSame(100, SaccoJournalLine::withoutGlobalScopes()->find($line->id)->debit_minor, 'nothing changed');

        $account = SaccoLedgerAccount::withoutGlobalScopes()->find($line->ledger_account_id);
        $this->assertImmutable(fn () => $account->delete());
        try {
            $account->update(['type' => 'expense']);
            $this->fail('an account with postings keeps its type');
        } catch (BusinessRuleException $e) {
            $this->assertSame('account_in_use', $e->errorCode());
        }
    }

    public function test_the_database_refuses_raw_edits_where_the_triggers_are_installed(): void
    {
        [, $entry, $line] = $this->rawEntry();
        $installed = DB::table('information_schema.triggers')->where('trigger_schema', DB::getDatabaseName())->where('trigger_name', 'like', 'sacco_journal_%')->count();
        if ($installed < 4) {
            $this->markTestSkipped('this database refused the triggers; LedgerVerifier catches raw edits by the hash chain instead');
        }
        foreach ([fn () => DB::table('sacco_journal_entries')->where('id', $entry->id)->update(['memo' => 'x']),
            fn () => DB::table('sacco_journal_lines')->where('id', $line->id)->update(['debit_minor' => 5]),
            fn () => DB::table('sacco_journal_lines')->where('id', $line->id)->delete(),
            fn () => DB::table('sacco_journal_entries')->where('id', $entry->id)->delete()] as $raw) {
            try {
                $raw();
                $this->fail('the trigger must refuse a raw change');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    public function test_the_constraints_are_known_to_schema_integrity(): void
    {
        $report = collect(SchemaIntegrity::run());
        foreach (['sacco_journal_lines.journal_entry_id → sacco_journal_entries', 'sacco_journal_lines.ledger_account_id → sacco_ledger_accounts',
            'sacco_journal_entries.period_id → sacco_periods', 'sacco_journal_entries.reverses_entry_id → sacco_journal_entries',
            'sacco_ledger_accounts.company_id → companies', 'sacco_account_balances.ledger_account_id → sacco_ledger_accounts'] as $fk) {
            $this->assertSame('ok', $report->firstWhere('target', $fk)['status'] ?? null, $fk);
        }
        $expected = SchemaIntegrity::checksSupported() ? 'ok' : 'app_only';
        foreach (['chk_sacco_journal_lines_one_side', 'chk_sacco_journal_lines_sub_pair', 'chk_sacco_journal_entries_total', 'chk_sacco_journal_entries_dates',
            'sacco_periods.status', 'sacco_ledger_accounts.type', 'sacco_journal_lines.sub_type'] as $check) {
            $this->assertSame($expected, $report->firstWhere('target', $check)['status'] ?? null, $check);
        }

        // Where CHECK is not enforced, the models guard the values (AK-56).
        $company = Company::factory()->create(['name' => 'Check SACCO', 'status' => 'Active']);
        try {
            SaccoPeriod::create(['company_id' => $company->id, 'year' => 2026, 'month' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'status' => 'whatever']);
            $this->fail('an unknown period status is refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame('invalid_status', $e->errorCode());
        }
    }

    public function test_every_ledger_table_is_per_company(): void
    {
        [$a, $entry] = $this->rawEntry();
        $b = Company::factory()->create(['name' => 'Other SACCO', 'status' => 'Active']);
        foreach ([SaccoJournalEntry::class, SaccoJournalLine::class, SaccoLedgerAccount::class, SaccoPeriod::class,
            \App\Models\SaccoLedgerHead::class, \App\Models\SaccoAccountBalance::class, SaccoPeriodBalance::class] as $model) {
            $this->assertArrayHasKey(CompanyScope::class, (new $model)->getGlobalScopes(), $model);
        }
        $this->assertSame(1, SaccoJournalEntry::forCompany($a->id)->count());
        $this->assertSame(0, SaccoJournalEntry::forCompany($b->id)->count());
        $this->assertNull(SaccoJournalEntry::forCompany($b->id)->find($entry->id));
    }

    public function test_the_migrations_are_safe_to_run_again(): void
    {
        foreach (self::MIGRATIONS as $file) {
            $migration = require database_path("migrations/{$file}.php");
            $migration->up();
        }
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('sacco_journal_lines'));
    }
}

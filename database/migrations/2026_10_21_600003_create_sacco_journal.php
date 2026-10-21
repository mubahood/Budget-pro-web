<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P1.4 (ADR 0009 §4, §5, §6): the journal. One entry per business event, two or more lines that
 * balance, written only by App\Services\Sacco\Ledger\PostingService and never updated or deleted: a mistake is a
 * reversal entry (reverses_entry_id, unique). Each entry carries the SHA-256 chain (prev_hash, hash).
 *
 * The model guards, the tests and the hash chain are the guarantee. On top, this migration tries to install
 * BEFORE UPDATE and BEFORE DELETE triggers that refuse the change; a host that refuses triggers (shared hosting
 * with binary logging) only logs it, and `sacco:ledger-verify` reports `triggers: absent`. The one way past a
 * trigger is the session flag @sacco_ledger_purge = 1, which only removing a whole demo or test company sets.
 */
return new class extends Migration
{
    public const TRIGGERS = [
        'sacco_journal_entries_no_update' => ['sacco_journal_entries', 'UPDATE'],
        'sacco_journal_entries_no_delete' => ['sacco_journal_entries', 'DELETE'],
        'sacco_journal_lines_no_update' => ['sacco_journal_lines', 'UPDATE'],
        'sacco_journal_lines_no_delete' => ['sacco_journal_lines', 'DELETE'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('sacco_journal_entries')) {
            Schema::create('sacco_journal_entries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('entry_no', 24); // JE-2026-000001 (NumberSequencer kind je)
                $t->date('entry_date'); // posting date: always in an open period
                $t->date('value_date'); // when the money moved (≤ entry_date; an offline receipt's own day)
                $t->unsignedBigInteger('period_id');
                $t->unsignedBigInteger('cycle_id')->nullable(); // group mode (P5)
                $t->string('kind', 24);
                $t->string('source_type', 40)->nullable();
                $t->unsignedBigInteger('source_id')->nullable();
                $t->string('idempotency_key', 120);
                $t->char('payload_hash', 64);
                $t->string('memo', 255)->nullable();
                $t->unsignedBigInteger('total_minor'); // Σ debits = Σ credits
                $t->char('currency', 3);
                $t->unsignedBigInteger('reverses_entry_id')->nullable();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('posted_by_id')->nullable(); // admin_users.id; null = the system
                $t->char('prev_hash', 64);
                $t->char('hash', 64);
                $t->timestamp('created_at')->useCurrent();

                $t->unique(['company_id', 'idempotency_key'], 'sacco_journal_entries_company_key_unique');
                $t->unique(['company_id', 'entry_no'], 'sacco_journal_entries_company_no_unique');
                $t->unique('reverses_entry_id', 'sacco_journal_entries_reverses_unique'); // reversed at most once
                $t->index(['company_id', 'entry_date'], 'sacco_journal_entries_company_date_idx');
                $t->index(['company_id', 'source_type', 'source_id'], 'sacco_journal_entries_company_source_idx');
                $t->index(['company_id', 'kind'], 'sacco_journal_entries_company_kind_idx');
            });
        }

        if (! Schema::hasTable('sacco_journal_lines')) {
            Schema::create('sacco_journal_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('journal_entry_id');
                $t->unsignedSmallInteger('line_no');
                $t->unsignedBigInteger('ledger_account_id');
                $t->unsignedBigInteger('debit_minor')->default(0);
                $t->unsignedBigInteger('credit_minor')->default(0);
                $t->string('sub_type', 16)->nullable(); // savings | shares | loan | fine | fund_due
                $t->unsignedBigInteger('sub_id')->nullable();
                $t->unsignedBigInteger('member_id')->nullable();
                $t->date('entry_date');
                $t->date('value_date');
                $t->string('memo', 255)->nullable();

                $t->unique(['journal_entry_id', 'line_no'], 'sacco_journal_lines_entry_line_unique');
                $t->index(['company_id', 'ledger_account_id', 'entry_date'], 'sacco_journal_lines_company_account_idx');
                $t->index(['company_id', 'sub_type', 'sub_id', 'entry_date'], 'sacco_journal_lines_company_sub_idx');
                $t->index(['company_id', 'member_id', 'entry_date'], 'sacco_journal_lines_company_member_idx');
            });
        }

        $this->installTriggers();
    }

    /** Best effort (ADR 0009 §5): refuse UPDATE and DELETE in the database too, where the host allows triggers. */
    private function installTriggers(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (self::TRIGGERS as $name => [$table, $event]) {
            $exists = DB::table('information_schema.triggers')->where('trigger_schema', DB::getDatabaseName())->where('trigger_name', $name)->exists();
            if ($exists) {
                continue;
            }
            try {
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW BEGIN "
                    ."IF COALESCE(@sacco_ledger_purge, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SACCO journals are append-only: post a reversal instead'; END IF; END");
            } catch (\Throwable $e) {
                Log::warning("[sacco] trigger {$name} not installed (the model guards and the hash chain still hold): ".$e->getMessage());
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TRIGGERS) as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
        }
        Schema::dropIfExists('sacco_journal_lines');
        Schema::dropIfExists('sacco_journal_entries');
    }
};

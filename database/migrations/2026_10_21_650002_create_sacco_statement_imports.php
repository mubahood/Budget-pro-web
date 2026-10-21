<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P3.4 (docs/akiba/04-architecture.md §3.5): statements from the bank, MTN, Airtel and Flutterwave,
 * matched against the books for 1100 Bank, 1150 MoMo float and 1160 MoMo clearing. A statement line only ever points
 * at the journal line it matches; matching proposes, a person confirms, and nothing is posted by an import (a missing
 * entry becomes a manual journal through its approval).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_statement_imports')) {
            Schema::create('sacco_statement_imports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('source', 12); // bank | mtn | airtel | flutterwave
                $t->unsignedBigInteger('ledger_account_id');
                $t->string('file_name', 190)->nullable();
                $t->char('file_hash', 64);
                $t->date('period_from')->nullable();
                $t->date('period_to')->nullable();
                $t->bigInteger('closing_balance_minor')->nullable(); // the statement's last running balance, when it has one
                $t->unsignedInteger('lines_count')->default(0);
                $t->string('status', 10)->default('open'); // open | closed
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'ledger_account_id', 'file_hash'], 'sacco_statement_imports_file_unique');
                $t->index(['company_id', 'ledger_account_id'], 'sacco_statement_imports_account_idx');
            });
        }
        if (! Schema::hasTable('sacco_statement_lines')) {
            Schema::create('sacco_statement_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('import_id');
                $t->unsignedInteger('line_no');
                $t->date('line_date');
                $t->string('reference', 120)->nullable();
                $t->string('description', 255)->nullable();
                $t->bigInteger('amount_minor'); // signed: + money into the account (a debit in the books), − out
                $t->bigInteger('balance_minor')->nullable();
                $t->string('match_status', 10)->default('unmatched'); // unmatched | proposed | matched | ignored
                $t->unsignedBigInteger('journal_line_id')->nullable();
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->unsignedSmallInteger('match_score')->nullable();
                $t->string('note', 255)->nullable();
                $t->unsignedBigInteger('reviewed_by_id')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->unsignedBigInteger('approval_id')->nullable(); // a manual journal asked for from this line
                $t->timestamps();

                $t->unique(['import_id', 'line_no'], 'sacco_statement_lines_no_unique');
                $t->index(['company_id', 'match_status'], 'sacco_statement_lines_status_idx');
                $t->index(['company_id', 'journal_line_id'], 'sacco_statement_lines_journal_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_statement_lines');
        Schema::dropIfExists('sacco_statement_imports');
    }
};

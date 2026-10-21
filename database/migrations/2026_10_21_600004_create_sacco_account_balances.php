<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P1.4 (ADR 0009 §7): each account's balance cache, written only by PostingService under the row
 * lock (the ADR 0002 pattern: the journal lines are the truth, this is their sum; LedgerVerifier checks it).
 * Then the ledger's foreign keys (ON DELETE RESTRICT) and CHECK constraints go on through SchemaIntegrity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_account_balances')) {
            Schema::create('sacco_account_balances', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('ledger_account_id');
                $t->unsignedBigInteger('debit_total_minor')->default(0);
                $t->unsignedBigInteger('credit_total_minor')->default(0);
                $t->bigInteger('balance_minor')->default(0); // signed, positive on the account's normal side
                $t->unsignedBigInteger('last_entry_id')->nullable();
                $t->timestamp('updated_at')->nullable();

                $t->unique(['company_id', 'ledger_account_id'], 'sacco_account_balances_company_account_unique');
            });
        }

        foreach (SchemaIntegrity::run(true) as $r) {
            if ($r['status'] === 'skipped') {
                Log::warning('[schema:integrity] '.$r['kind'].' '.$r['target'].' skipped: '.($r['detail'] ?? ''));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_account_balances');
    }
};

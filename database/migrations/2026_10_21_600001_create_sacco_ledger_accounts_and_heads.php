<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P1.4 (docs/akiba/05-roadmap.md, ADR 0009 §3, §4.3): each SACCO's chart of accounts and its ledger
 * head. Accounts are seeded from database/data/sacco_chart.php (ChartOfAccounts::seed), added by maker-checker
 * and never deleted (deactivated). The head is the per-SACCO posting lock and the end of the hash chain: one
 * row per company, created with the chart. Money is never here: balances are in sacco_account_balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_ledger_accounts')) {
            Schema::create('sacco_ledger_accounts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('code', 16);
                $t->string('name', 120);
                $t->string('type', 10); // asset | liability | equity | income | expense
                $t->string('normal_side', 6); // debit | credit
                $t->unsignedBigInteger('parent_id')->nullable(); // a header (is_postable = 0) or a parent account
                $t->boolean('is_postable')->default(true);
                $t->string('control_of', 16)->nullable(); // savings | shares | loan_principal | loan_interest | loan_penalty | loan_fees | fines | fund
                $t->string('system_key', 40)->nullable(); // cash.office, suspense.receipts…: the accounts the code posts to
                $t->string('umra_line', 16)->nullable(); // Schedule 4 form line, e.g. F5.1.1
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort')->default(0);
                $t->unsignedBigInteger('created_by_id')->nullable(); // admin_users.id (null = seeded)
                $t->timestamps();

                $t->unique(['company_id', 'code'], 'sacco_ledger_accounts_company_code_unique');
                $t->unique(['company_id', 'system_key'], 'sacco_ledger_accounts_company_key_unique');
                $t->index(['company_id', 'parent_id'], 'sacco_ledger_accounts_company_parent_idx');
                $t->index(['company_id', 'control_of'], 'sacco_ledger_accounts_company_control_idx');
            });
        }

        if (! Schema::hasTable('sacco_ledger_heads')) {
            Schema::create('sacco_ledger_heads', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->primary();
                $t->unsignedBigInteger('last_entry_id')->nullable();
                $t->char('last_hash', 64)->nullable();
                $t->unsignedBigInteger('entries_count')->default(0);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_ledger_heads');
        Schema::dropIfExists('sacco_ledger_accounts');
    }
};

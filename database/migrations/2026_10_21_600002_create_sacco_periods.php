<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P1.4 (ADR 0009 §8, AK-53): calendar-month periods and their closing snapshots. A posting's
 * entry date is always in an open period. Closing writes one snapshot row per account (opening, debits,
 * credits, closing, and the head hash as the period's anchor), which is never changed: a reopened period
 * that closes again writes a new snapshot (snapshot_no + 1) beside the old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_periods')) {
            Schema::create('sacco_periods', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedSmallInteger('year');
                $t->unsignedTinyInteger('month');
                $t->date('starts_on');
                $t->date('ends_on');
                $t->string('status', 10)->default('open'); // open | closing | closed
                $t->unsignedSmallInteger('snapshot_no')->default(0); // the latest closing snapshot (0 = never closed)
                $t->char('close_hash', 64)->nullable(); // the ledger head's hash at the latest close (the anchor, ADR 0009 §6)
                $t->unsignedBigInteger('closed_by_id')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedSmallInteger('reopen_count')->default(0);
                $t->unsignedBigInteger('reopened_by_id')->nullable();
                $t->timestamp('reopened_at')->nullable();
                $t->string('reopen_reason', 255)->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'year', 'month'], 'sacco_periods_company_month_unique');
                $t->index(['company_id', 'status'], 'sacco_periods_company_status_idx');
            });
        }

        if (! Schema::hasTable('sacco_period_balances')) {
            Schema::create('sacco_period_balances', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('period_id');
                $t->unsignedSmallInteger('snapshot_no')->default(1);
                $t->unsignedBigInteger('ledger_account_id');
                $t->bigInteger('opening_minor'); // signed, positive on the account's normal side
                $t->unsignedBigInteger('debits_minor');
                $t->unsignedBigInteger('credits_minor');
                $t->bigInteger('closing_minor');
                $t->char('head_hash', 64)->nullable();
                $t->timestamp('created_at')->useCurrent();

                $t->unique(['period_id', 'snapshot_no', 'ledger_account_id'], 'sacco_period_balances_snapshot_unique');
                $t->index(['company_id', 'ledger_account_id'], 'sacco_period_balances_company_account_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_period_balances');
        Schema::dropIfExists('sacco_periods');
    }
};

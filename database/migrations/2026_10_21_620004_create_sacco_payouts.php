<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P2.9 (docs/akiba/04-architecture.md §3.5, §11.2, AK-17): money sent to a member's mobile wallet (a loan
 * paid out by MoMo; withdrawals, share-outs and dividends later). One row per payout, idempotent on its key; the
 * journals it posts are named here (sent: Dr the loan, Cr 2160 Payouts in transit; settled: Dr 2160, Cr 1150 MoMo
 * float; failed: the sent journal reversed). The driver is `off` until the owner adds keys, `simulated` locally and in
 * tests: no live traffic from dev or tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_payouts')) {
            Schema::create('sacco_payouts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('uuid', 36);
                $t->string('purpose', 20); // loan_disbursement | withdrawal | shareout | dividend | exit
                $t->string('subject_type', 40)->nullable();
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->unsignedBigInteger('member_id')->nullable();
                $t->string('phone_e164', 20);
                $t->string('network', 10)->nullable(); // mtn | airtel
                $t->unsignedBigInteger('amount_minor');
                $t->unsignedBigInteger('fee_minor')->default(0);
                $t->char('currency', 3);
                $t->string('provider', 16); // simulated | flutterwave
                $t->string('provider_ref', 80)->nullable();
                $t->string('idempotency_key', 80);
                $t->string('status', 10)->default('queued'); // queued | sent | successful | failed | unknown | reversed
                $t->unsignedInteger('attempts')->default(0);
                $t->string('last_error', 255)->nullable();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('sent_journal_id')->nullable();
                $t->unsignedBigInteger('settled_journal_id')->nullable();
                $t->unsignedBigInteger('failed_journal_id')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('resolved_at')->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'uuid'], 'sacco_payouts_uuid_unique');
                $t->unique('idempotency_key', 'sacco_payouts_key_unique');
                $t->index(['company_id', 'status'], 'sacco_payouts_status_idx');
                $t->index(['company_id', 'subject_type', 'subject_id'], 'sacco_payouts_subject_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_payouts');
    }
};

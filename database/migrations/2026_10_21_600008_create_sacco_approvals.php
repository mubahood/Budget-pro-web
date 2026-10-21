<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P1.10 (docs/akiba/04-architecture.md §3.3, §7, AK-22): maker-checker requests and their votes. A
 * request freezes its payload and the payload's hash, so the checker approves exactly what executes; it expires
 * after 7 days and an expired request never executes; executing twice gives the first result.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_approvals')) {
            Schema::create('sacco_approvals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('uuid', 36); // also the API idempotency key, and the posting key apr:{uuid}
                $t->string('action', 32);
                $t->string('subject_type', 40)->nullable();
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->json('payload');
                $t->char('payload_hash', 64);
                $t->unsignedBigInteger('amount_minor')->nullable();
                $t->unsignedTinyInteger('required_approvals')->default(1);
                $t->string('status', 12)->default('pending'); // pending | approved | rejected | cancelled | expired | executed
                $t->unsignedBigInteger('requested_by_id')->nullable(); // null = the system (a held receipt from sync)
                $t->dateTime('requested_at'); // UTC (DATETIME: MySQL 5.7 gives a second NOT NULL TIMESTAMP no default)
                $t->date('requested_on'); // the SACCO's day, for the month-close check
                $t->dateTime('expires_at'); // UTC
                $t->timestamp('decided_at')->nullable();
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->string('decision_note', 255)->nullable();
                $t->boolean('self_approved')->default(false); // single-officer mode (group mode only, AK-22)
                $t->timestamp('executed_at')->nullable();
                $t->unsignedBigInteger('executed_entry_id')->nullable();
                $t->json('result')->nullable();
                $t->string('last_error', 255)->nullable(); // why an approved request has not executed yet
                $t->timestamps();

                $t->unique(['company_id', 'uuid'], 'sacco_approvals_company_uuid_unique');
                $t->index(['company_id', 'status', 'action'], 'sacco_approvals_company_status_idx');
                $t->index(['company_id', 'subject_type', 'subject_id'], 'sacco_approvals_company_subject_idx');
                $t->index(['company_id', 'requested_on'], 'sacco_approvals_company_day_idx');
            });
        }

        if (! Schema::hasTable('sacco_approval_votes')) {
            Schema::create('sacco_approval_votes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('approval_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('officer_id')->nullable();
                $t->string('decision', 8); // approve | reject
                $t->string('note', 255)->nullable();
                $t->boolean('self_approved')->default(false);
                $t->timestamp('created_at')->useCurrent();

                $t->unique(['approval_id', 'user_id'], 'sacco_approval_votes_one_each');
                $t->index(['company_id', 'user_id'], 'sacco_approval_votes_company_user_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_approval_votes');
        Schema::dropIfExists('sacco_approvals');
    }
};

<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P1.11 (docs/akiba/04-architecture.md §3.3, ADR 0009 §7): one share account per member, a row of the
 * shares sub-ledger under 3100 Share capital. balance_minor is the sum of its 3100 lines (PostingService only);
 * shares_count is the number of shares those lines bought (ShareService, in the same transaction). Sync columns,
 * like the savings accounts, so phones pull them (snapshot, pull only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_share_accounts')) {
            Schema::create('sacco_share_accounts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('member_id');
                $t->string('account_no', 24); // NumberSequencer kind shr: SHR-000123
                $t->unsignedBigInteger('ledger_account_id'); // 3100
                $t->unsignedInteger('shares_count')->default(0);
                $t->bigInteger('balance_minor')->default(0); // cache: PostingService only
                $t->date('opened_on');
                $t->string('status', 10)->default('active'); // active | closed
                $t->timestamps();
                $t->string('uuid', 36);
                $t->unsignedBigInteger('server_seq')->default(0);
                $t->unsignedInteger('version')->default(1);
                $t->boolean('is_deleted')->default(false);
                $t->unsignedBigInteger('client_created_at')->nullable();
                $t->unsignedBigInteger('client_updated_at')->nullable();
                $t->string('device_id', 64)->nullable();

                $t->unique(['company_id', 'member_id'], 'sacco_share_accounts_member_unique');
                $t->unique(['company_id', 'account_no'], 'sacco_share_accounts_no_unique');
                $t->unique(['company_id', 'uuid'], 'sacco_share_accounts_uuid_unique');
                $t->index(['company_id', 'server_seq'], 'sacco_share_accounts_seq_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_share_accounts');
    }
};

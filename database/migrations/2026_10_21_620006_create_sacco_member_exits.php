<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P2.15 (docs/akiba/04-architecture.md §3.4, §7 `member_exit`): a member leaving the SACCO. The settlement
 * (shares to another member, savings paid out) is frozen in the request and executed by approval as ordinary
 * journals; the member's history stays as it was, and the member becomes `exited`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_member_exits')) {
            Schema::create('sacco_member_exits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('member_id');
                $t->string('reason', 10); // voluntary | expelled | deceased
                $t->string('note', 255)->nullable();
                $t->date('requested_on');
                $t->json('settlement');
                $t->string('status', 10)->default('requested'); // requested | completed | rejected | cancelled
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->json('journal_entry_ids')->nullable();
                $t->date('completed_on')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'member_id'], 'sacco_member_exits_member_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_member_exits');
    }
};

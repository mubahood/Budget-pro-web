<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P1.8 (docs/akiba/04-architecture.md §3.1, §6.2, AK-21): who holds an office in a SACCO. A permission
 * says who may press a button; a position says who chairs, who sits on the loans committee, who audits, who holds a
 * key. Terms end (ends_on) and rows are never deleted. A group-mode key holder may have no login (user_id null).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_officers')) {
            Schema::create('sacco_officers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('member_id')->nullable(); // sacco_members.id: an officer is usually a member
                $t->unsignedBigInteger('user_id')->nullable(); // admin_users.id: null for a key holder without a login
                $t->string('name', 150)->nullable(); // for an officer with neither (a group's key holder)
                $t->string('position', 24);
                $t->string('title', 60)->nullable(); // for `custom`
                $t->unsignedBigInteger('approval_limit_minor')->nullable(); // delegated loan approval limit (Coop s.55AH)
                $t->date('starts_on');
                $t->date('ends_on')->nullable();
                $t->string('end_reason', 255)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->unsignedBigInteger('ended_by_id')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'position', 'ends_on'], 'sacco_officers_company_position_idx');
                $t->index(['company_id', 'user_id'], 'sacco_officers_company_user_idx');
                $t->index(['company_id', 'member_id'], 'sacco_officers_company_member_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_officers');
    }
};

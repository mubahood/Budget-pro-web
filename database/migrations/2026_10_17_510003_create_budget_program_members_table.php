<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The committee of an event (roadmap 02 §6): who helps run it and in which role (chair, treasurer,
 * secretary, member, viewer). A person is a team member of the account first; someone invited by phone
 * waits here with invite_id until they accept (TeamService), then user_id is filled in.
 * Synced (ADR 0001); a removal is a tombstone, so a person can be added again later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('budget_program_members')) {
            return;
        }
        Schema::create('budget_program_members', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36);
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('budget_program_id');
            $table->unsignedBigInteger('user_id')->nullable(); // admin_users.id; null while the invite is open
            $table->unsignedBigInteger('invite_id')->nullable(); // invites.id for someone invited by phone
            $table->string('role', 20)->default('member');
            $table->string('status', 20)->default('active'); // active | invited
            $table->string('name', 191)->nullable(); // shown while invited
            $table->string('phone', 20)->nullable(); // E.164, shown while invited
            $table->unsignedBigInteger('added_by_id')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('server_seq')->default(0);
            $table->unsignedBigInteger('client_created_at')->nullable();
            $table->unsignedBigInteger('client_updated_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_deleted')->default(false);
            $table->string('device_id', 100)->nullable();

            $table->unique(['company_id', 'uuid'], 'budget_program_members_company_uuid_unique');
            $table->index(['company_id', 'server_seq'], 'budget_program_members_company_seq_idx');
            $table->index(['budget_program_id', 'user_id', 'is_deleted'], 'budget_program_members_program_user_idx');
            $table->index('invite_id', 'budget_program_members_invite_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_program_members');
    }
};

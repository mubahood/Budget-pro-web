<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team chat (App\Services\Chat\ChatService): conversations between members of one shop, their
 * participants (with a read pointer) and messages; and admin_users.last_active_at for "online now".
 * Additive and guarded, so it can run again safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_conversations')) {
            Schema::create('chat_conversations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('type', 10)->default('direct'); // direct | team
                // "<low user id>:<high user id>" for a direct chat, "team" for the whole-team group: one of each per shop.
                $t->string('direct_key', 64)->nullable();
                $t->string('title')->nullable();
                $t->timestamp('last_message_at')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'direct_key']);
                $t->index(['company_id', 'last_message_at']);
            });
        }
        if (! Schema::hasTable('chat_participants')) {
            Schema::create('chat_participants', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('last_read_message_id')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
                $t->unique(['conversation_id', 'user_id']);
                $t->index('user_id');
            });
        }
        if (! Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('conversation_id');
                $t->unsignedBigInteger('user_id');
                $t->text('body')->nullable();
                $t->string('attachment_path')->nullable();
                $t->string('attachment_mime', 50)->nullable();
                $t->timestamp('deleted_at')->nullable();
                $t->timestamps();
                $t->index(['conversation_id', 'id']);
                $t->index(['company_id', 'conversation_id']);
            });
        }
        if (Schema::hasTable('admin_users') && ! Schema::hasColumn('admin_users', 'last_active_at')) {
            Schema::table('admin_users', function (Blueprint $t) {
                $t->timestamp('last_active_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_conversations');
        if (Schema::hasColumn('admin_users', 'last_active_at')) {
            Schema::table('admin_users', function (Blueprint $t) {
                $t->dropColumn('last_active_at');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| "Open on the web" from the phone (MOBILE_EXCELLENCE_PLAN.md D7, App\Support\WebLink): each signed
| link the kernel hands out, by the hash of its nonce, so the web portal can take it once only
| (`used_at`). Links last two minutes; rows are pruned daily by `api:prune-keys`.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('web_link_tokens')) {
            return;
        }
        Schema::create('web_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('nonce_hash', 64)->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('path', 500);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_link_tokens');
    }
};

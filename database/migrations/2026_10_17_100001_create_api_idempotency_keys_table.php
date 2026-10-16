<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Safe retries for the phone's writes (MOBILE_EXCELLENCE_PLAN.md D5, App\Http\Middleware\IdempotentRequests):
| the first answer to a write sent with an Idempotency-Key, kept 24 hours per (company, user, key) and
| replayed when the same request comes again. `status` NULL = the first request is still running.
| Pruned daily by `api:prune-keys`.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_idempotency_keys')) {
            return;
        }
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('key', 100);
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('content_type', 100)->nullable();
            $table->longText('body')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->unique(['company_id', 'user_id', 'key'], 'api_idem_owner_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};

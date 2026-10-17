<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The API's password-reset token and the sign-up's "this phone or email was verified" token
| (App\Services\Auth\OneTimeToken). They used to be values encrypted with APP_KEY, which anyone who
| holds that key could forge. Now each is 32 random bytes, kept here only as its SHA-256 hash, with
| its purpose, the account or identifier it belongs to, its expiry and when it was used. A table,
| not the cache: the production cache is files on shared hosting, which cannot use a token up
| atomically and is emptied on deploy. Rows are pruned a day after they expire (`api:prune-keys`).
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_one_time_tokens')) {
            return;
        }
        Schema::create('auth_one_time_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 30);
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('identifier', 191)->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_one_time_tokens');
    }
};

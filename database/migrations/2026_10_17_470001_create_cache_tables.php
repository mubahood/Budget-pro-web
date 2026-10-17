<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Laravel's database cache tables, for the attempt limits (config/cache.php `limiter`, App\Support\SignInThrottle).
| The production cache is files, whose increments are not atomic: two sign-in tries sent at the same moment
| could both pass a limit. Counted in these rows they cannot. budget-pro-new counts here too, with its own
| key prefix (it ships no migrations). Expired rows are deleted daily by `api:prune-keys`.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration')->index();
            });
        }
        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P4-8 (plan B10 step 4): daily counts of calls to the pre-v1 routes, to know when the old app can be retired. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legacy_calls')) {
            Schema::create('legacy_calls', function (Blueprint $t) {
                $t->id();
                $t->date('day');
                $t->string('route', 120);
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedInteger('calls')->default(0);
                $t->timestamps();
                $t->unique(['day', 'route', 'company_id', 'user_id'], 'legacy_calls_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_calls');
    }
};

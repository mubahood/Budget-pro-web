<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding (POWER_PLAN §3.1): a log of first-run events per shop (how long a new shop takes to its
 * first sale, which steps get skipped) and the link from a demo shop to the real shop that made it.
 * Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('onboarding_events')) {
            Schema::create('onboarding_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('event', 60);
                $t->string('channel', 12)->nullable(); // web | classic | app | system
                $t->json('meta')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'event'], 'onboarding_events_company_event_idx');
            });
        }
        if (! Schema::hasColumn('companies', 'demo_parent_id')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->unsignedBigInteger('demo_parent_id')->nullable()->index('companies_demo_parent_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_events');
        if (Schema::hasColumn('companies', 'demo_parent_id')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropIndex('companies_demo_parent_idx');
                $t->dropColumn('demo_parent_id');
            });
        }
    }
};

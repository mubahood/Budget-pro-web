<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Who uses the apps, and the emails that bring people back (App\Services\Platform\Activity, the portal's App\Shop\Engage).
|
| - user_activity_days: one row per person per day per door (web, phone). Written at most every few minutes by
|   Activity::touch(), so the Platform pages can draw daily use, last seen and the phone app version in use.
| - engagement_emails: every "we fixed it", "we miss you" and "finish signing up" email, with what happened next:
|   clicked (the sign-in page was opened), came back (used an app after it).
| - email_opt_outs: addresses that said "stop these emails". Sign-in codes and receipts still go; these never do.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_activity_days')) {
            Schema::create('user_activity_days', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->date('day');
                $table->string('source', 10); // web | phone
                $table->string('app_version', 20)->nullable();
                $table->unsignedInteger('hits')->default(1);
                $table->timestamp('first_at')->nullable();
                $table->timestamp('last_at')->nullable();
                $table->unique(['user_id', 'day', 'source']);
                $table->index(['day', 'source']);
            });
        }

        if (! Schema::hasTable('engagement_emails')) {
            Schema::create('engagement_emails', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('email', 191)->index();
                $table->string('kind', 30); // fixed | nudge | finish-signup
                $table->unsignedSmallInteger('step')->default(0); // nudge: which one in the series
                $table->string('app_key', 40)->nullable();
                $table->string('subject', 255)->nullable();
                $table->string('status', 12)->default('sent'); // sent | failed
                $table->string('error', 500)->nullable();
                $table->char('click_hash', 64)->nullable()->unique(); // the email's own sign-in link, by hash
                $table->timestamp('sent_at')->nullable()->index();
                $table->timestamp('clicked_at')->nullable();
                $table->timestamp('returned_at')->nullable();
                $table->index(['kind', 'sent_at']);
            });
        }

        if (! Schema::hasTable('email_opt_outs')) {
            Schema::create('email_opt_outs', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191)->unique();
                $table->string('reason', 40)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_opt_outs');
        Schema::dropIfExists('engagement_emails');
        Schema::dropIfExists('user_activity_days');
    }
};

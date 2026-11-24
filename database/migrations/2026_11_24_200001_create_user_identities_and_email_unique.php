<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One person, many ways in (budget-pro-new/docs/accounts/01-one-person-many-apps.md):
 *  - user_identities: a Google account (its `sub`, which never changes even if the Gmail address does) linked to the login it opens;
 *  - the "Connect Google" nudge on admin_users: when it may show again and how often it was put off (it stops after 3);
 *  - a unique sign-in email. Live had no duplicates on 2026-11-24 (114 logins); if a copy of the database does, the index is left out
 *    and the ids are logged for a platform admin, and nothing is changed or merged.
 * Dates are DATETIME, not TIMESTAMP: MySQL's ON UPDATE trap once expired every invite on arrival.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_identities')) {
            Schema::create('user_identities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index()->comment('the sign-in login (never a sibling login)');
                $table->string('provider', 20)->comment('google');
                $table->string('subject', 191)->comment("the provider's stable id for the person (Google: sub)");
                $table->string('email', 191)->nullable()->comment('the address the provider gave when linked, for display');
                $table->dateTime('linked_at')->nullable();
                $table->dateTime('last_used_at')->nullable();
                $table->unique(['provider', 'subject']);
            });
        }
        Schema::table('admin_users', function (Blueprint $table) {
            if (! Schema::hasColumn('admin_users', 'google_nudge_until')) {
                $table->dateTime('google_nudge_until')->nullable()->comment('Connect Google is not shown before this');
            }
            if (! Schema::hasColumn('admin_users', 'google_nudge_count')) {
                $table->unsignedTinyInteger('google_nudge_count')->default(0)->comment('times put off; 3 and it stops');
            }
        });

        $exists = DB::selectOne("SELECT COUNT(*) c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND index_name = 'admin_users_email_unique'");
        if ((int) $exists->c > 0) {
            return;
        }
        DB::table('admin_users')->where('email', '')->update(['email' => null]);
        $dups = DB::table('admin_users')->whereNotNull('email')->selectRaw('LOWER(TRIM(email)) e, GROUP_CONCAT(id) ids')->groupBy('e')->havingRaw('COUNT(*) > 1')->pluck('ids');
        if ($dups->isNotEmpty()) {
            Log::warning('admin_users email unique index left out: the same email on more than one login', ['ids' => $dups->all()]);

            return;
        }
        DB::statement('ALTER TABLE admin_users ADD UNIQUE admin_users_email_unique (email)');
    }

    public function down(): void
    {
        $exists = DB::selectOne("SELECT COUNT(*) c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND index_name = 'admin_users_email_unique'");
        if ((int) $exists->c > 0) {
            DB::statement('ALTER TABLE admin_users DROP INDEX admin_users_email_unique');
        }
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn(['google_nudge_until', 'google_nudge_count']);
        });
        Schema::dropIfExists('user_identities');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Push notifications for the phone apps (App\Services\Notifications\Notifier::send, PushSender).
|
| - devices: the phone's FCM token, which app it is (AppFamily slug), its language, and the sign-in that registered the token
|   (signing out of that sign-in clears it). One token sits on one device row at a time.
| - app_notifications: which app the inbox row belongs to, its dedupe key (the same message once only), and what happened to its
|   push: pending (waiting for quiet hours to end), sent, skipped (no phone, cap or preference), and when a tap opened it.
| - notification_preferences: a saved choice that has the inbox gets push too (a new channel; the person can turn it off).
|
| Dates are DATETIME, not TIMESTAMP: on MySQL 5.7 a table's first plain TIMESTAMP gets ON UPDATE CURRENT_TIMESTAMP.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (! Schema::hasColumn('devices', 'push_token')) {
                $table->string('push_token', 255)->nullable()->after('app_version');
                $table->string('push_provider', 10)->nullable()->after('push_token');
                $table->string('app', 40)->nullable()->after('push_provider');
                $table->string('locale', 12)->nullable()->after('app');
                $table->unsignedBigInteger('access_token_id')->nullable()->after('locale');
                $table->dateTime('push_token_at')->nullable()->after('access_token_id');
                $table->index('push_token', 'devices_push_token_index');
                $table->index(['user_id', 'app'], 'devices_user_app_index');
                $table->index('access_token_id', 'devices_access_token_index');
            }
        });

        Schema::table('app_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('app_notifications', 'app')) {
                $table->string('app', 40)->nullable()->after('user_id');
                $table->string('link', 191)->nullable()->after('body');
                $table->string('dedupe_key', 120)->nullable()->after('link');
                $table->string('push_status', 10)->nullable()->after('dedupe_key'); // pending | sent | skipped | failed
                $table->dateTime('push_due_at')->nullable()->after('push_status');
                $table->dateTime('pushed_at')->nullable()->after('push_due_at');
                $table->dateTime('opened_at')->nullable()->after('pushed_at'); // a tap on the push opened it
                $table->boolean('urgent')->default(false)->after('opened_at');
                $table->unique('dedupe_key', 'app_notifications_dedupe_unique');
                $table->index(['push_status', 'push_due_at'], 'app_notifications_push_due_index');
                $table->index(['user_id', 'app', 'pushed_at'], 'app_notifications_user_app_pushed_index');
            }
        });

        $this->pushJoinsSavedPreferences();
    }

    /** Preferences saved before push existed: push goes where the person already gets the inbox entry. */
    private function pushJoinsSavedPreferences(): void
    {
        \Illuminate\Support\Facades\DB::table('notification_preferences')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $channels = json_decode((string) $row->channels, true) ?: [];
                if (in_array('in_app', $channels, true) && ! in_array('push', $channels, true)) {
                    $channels[] = 'push';
                    \Illuminate\Support\Facades\DB::table('notification_preferences')->where('id', $row->id)->update(['channels' => json_encode($channels)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('app_notifications', 'app')) {
                $table->dropUnique('app_notifications_dedupe_unique');
                $table->dropIndex('app_notifications_push_due_index');
                $table->dropIndex('app_notifications_user_app_pushed_index');
                $table->dropColumn(['app', 'link', 'dedupe_key', 'push_status', 'push_due_at', 'pushed_at', 'opened_at', 'urgent']);
            }
        });
        Schema::table('devices', function (Blueprint $table) {
            if (Schema::hasColumn('devices', 'push_token')) {
                $table->dropIndex('devices_push_token_index');
                $table->dropIndex('devices_user_app_index');
                $table->dropIndex('devices_access_token_index');
                $table->dropColumn(['push_token', 'push_provider', 'app', 'locale', 'access_token_id', 'push_token_at']);
            }
        });
    }
};

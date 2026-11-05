<?php

use App\Support\DataCrypt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Team invite links that can be copied at any time, and an owner's control of a login.
 *
 *  - invites.token_secret: the link's token, encrypted with the key both apps share (DataCrypt). The hash stays what a link is
 *    looked up by. Open invites had only a hash, so they get a new token: their old links stop working, and the new one is on the
 *    Team page. Expired ones get 7 more days only when someone copies them.
 *  - admin_users.password_pending_at: joined by the link and no password chosen yet. While this is set, the link still signs them in.
 *  - admin_users.sessions_valid_from: browser sessions from before this moment are ended (disabled, or signed out by the owner).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $t) {
            if (! Schema::hasColumn('invites', 'token_secret')) {
                $t->text('token_secret')->nullable();
            }
        });
        Schema::table('admin_users', function (Blueprint $t) {
            if (! Schema::hasColumn('admin_users', 'password_pending_at')) {
                $t->timestamp('password_pending_at')->nullable();
            }
            if (! Schema::hasColumn('admin_users', 'sessions_valid_from')) {
                $t->timestamp('sessions_valid_from')->nullable();
            }
        });

        foreach (DB::table('invites')->where('status', 'pending')->whereNull('token_secret')->pluck('id') as $id) {
            $token = Str::random(40);
            DB::table('invites')->where('id', $id)->update(['token_hash' => hash('sha256', $token), 'token_secret' => DataCrypt::encrypt($token)]);
        }
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $t) {
            if (Schema::hasColumn('invites', 'token_secret')) {
                $t->dropColumn('token_secret');
            }
        });
        Schema::table('admin_users', function (Blueprint $t) {
            foreach (['password_pending_at', 'sessions_valid_from'] as $column) {
                if (Schema::hasColumn('admin_users', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};

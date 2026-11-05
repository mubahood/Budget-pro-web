<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The first proof that a person owns an account's address. Signing up needs no code, so anyone can make an account with someone
 * else's email and a password of their own. When the real owner first proves that email (Google, a code to their inbox), they get
 * the account, and whatever was set up before the proof stops working: the password, every signed-in phone, every remembered browser.
 * The same goes for an account beside it (SiblingAccounts). An account with a proven email or phone already is left alone.
 */
final class EmailProof
{
    public static function claim(User $user): void
    {
        if ($user->email_verified_at !== null || $user->phone_verified_at !== null) {
            return;
        }
        $ids = collect([(int) $user->id]);
        if (Schema::hasTable('account_siblings')) {
            $ids = $ids->merge(DB::table('account_siblings')->where('user_id', $user->id)->pluck('sibling_user_id')->map(fn ($id) => (int) $id));
        }
        $fresh = Hash::make(Str::password(40));
        $now = now();
        foreach (User::withoutGlobalScopes()->whereIn('id', $ids->all())->get() as $u) {
            $u->forceFill(array_filter(['password' => $fresh, 'remember_token' => Str::random(60), 'sessions_valid_from' => Schema::hasColumn('admin_users', 'sessions_valid_from') ? $now : null]))->saveQuietly();
            $u->tokens()->delete();
        }
        $user->password = $fresh;
    }
}

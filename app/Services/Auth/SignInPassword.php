<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\MasterPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The password check on the sign-in forms (classic and web) and the legacy API sign-in, made so that its
 * timing does not tell whether an account exists. A known account with a wrong password costs one
 * bcrypt check, plus the master password's when its sign-in-form path is on
 * (MasterPassword::onSignInForms). An unknown account costs the same: one check against a stand-in
 * hash, and a second one when that path is on.
 */
final class SignInPassword
{
    /** True when $password is $user's own password. Null $user: the same work, and false. */
    public static function matches(?User $user, string $password): bool
    {
        if ($user === null) {
            self::standIn($password);
            if (MasterPassword::onSignInForms()) {
                self::standIn($password); // the master password check a known account would get
            }

            return false;
        }
        $hash = (string) $user->password;
        if ($hash === '') {
            self::standIn($password);

            return false;
        }
        try {
            return Hash::check($password, $hash);
        } catch (\RuntimeException) {
            self::standIn($password); // a hash from another algorithm: a wrong password, at the same cost

            return false;
        }
    }

    /** One check against a hash of a random value, made once at this server's cost and kept. */
    private static function standIn(string $password): void
    {
        $key = 'signin:stand-in-hash:'.config('hashing.driver').':'.config('hashing.bcrypt.rounds').':'.config('hashing.argon.time');
        Hash::check($password, (string) Cache::rememberForever($key, fn () => Hash::make(Str::random(40))));
    }
}

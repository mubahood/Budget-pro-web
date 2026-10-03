<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The owner's master password (MASTER_PASSWORD in .env): signs into any existing account, found by
 * email, username or phone, on the classic screens and the new interface. Empty means off. Every
 * use is logged with the account and the address it came from.
 */
final class MasterPassword
{
    public static function matches(?string $password): bool
    {
        $master = (string) config('saas.master_password', '');

        return $master !== '' && $password !== null && hash_equals($master, $password);
    }

    /** True (and logged) when $password is the master password. */
    public static function allows(?string $password, User $user, string $where): bool
    {
        if (! self::matches($password)) {
            return false;
        }
        Log::warning('[auth] master password used', ['user_id' => $user->id, 'username' => $user->username, 'company_id' => $user->company_id,
            'where' => $where, 'ip' => request()?->ip()]);

        return true;
    }
}

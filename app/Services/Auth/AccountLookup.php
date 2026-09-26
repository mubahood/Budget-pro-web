<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Phone;

/**
 * Who is signing in: one rule for every door (the mobile API, the classic admin's username, and
 * the new shop interface). Email (any case), a phone number in any local or international form,
 * the legacy phone field, or the laravel-admin username.
 */
class AccountLookup
{
    public static function find(string $raw): ?User
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, '@')) {
            return User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [strtolower($raw)])->first()
                ?? User::withoutGlobalScopes()->whereRaw('LOWER(username) = ?', [strtolower($raw)])->first();
        }
        foreach (array_keys(Phone::COUNTRIES) as $country) {
            $e164 = Phone::e164($raw, $country);
            if ($e164 && ($u = User::withoutGlobalScopes()->where('phone_e164', $e164)->first())) {
                return $u;
            }
        }

        return User::withoutGlobalScopes()->where('phone_number', $raw)->first()
            ?? User::withoutGlobalScopes()->where('username', $raw)->first();
    }
}

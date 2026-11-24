<?php

namespace App\Services\Platform;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Logins that look like one person signed up twice (budget-pro-new/docs/accounts/03): the same email (ignoring case and spaces)
 * or the same phone number on more than one login. For platform admins to look at and talk to the person; nothing is merged
 * here. Logins made by "join this app" (account_siblings) and demo accounts are left out: those are one person on purpose.
 */
class SamePersonTwice
{
    /** @return Collection<int, array{by: string, value: string, logins: Collection}> */
    public function groups(): Collection
    {
        $siblings = Schema::hasTable('account_siblings') ? DB::table('account_siblings')->pluck('sibling_user_id')->all() : [];
        $users = DB::table('admin_users as u')
            ->leftJoin('companies as c', 'c.id', '=', 'u.company_id')
            ->whereNotIn('u.id', $siblings)
            ->where(fn ($q) => $q->whereNull('c.id')->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('c.is_demo')->orWhere('c.is_demo', 0))->whereNull('c.demo_parent_id')))
                        ->get(['u.id', 'u.name', 'u.email', 'u.phone_e164', 'u.phone_number', 'u.company_id', 'u.created_at', 'c.name as company', 'c.enabled_modules']);

        $byKey = [];
        foreach ($users as $u) {
            foreach (self::keys($u) as $key) {
                $byKey[$key][$u->id] = $u;
            }
        }
        $out = collect();
        foreach ($byKey as $key => $group) {
            if (count($group) < 2) {
                continue;
            }
            [$by, $value] = explode(':', $key, 2);
            $out->push(['by' => $by, 'value' => $by === 'phone' ? '…'.$value : $value, 'logins' => collect($group)->sortBy('id')->values()->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone_e164 ?: $u->phone_number,
                'company_id' => $u->company_id, 'company' => $u->company, 'app' => PlatformOverview::appOf($u->enabled_modules),
                'created' => $u->created_at,
            ])]);
        }

        return $out->sortByDesc(fn ($g) => $g['logins']->max('created'))->values();
    }

    /**
     * What makes two logins one person: the email ignoring case and spaces, and every phone number on the login by its last nine
     * digits, so 0700 111222, 256700111222 and +256 700 111 222 meet (the unique indexes only stop exact repeats).
     *
     * @return list<string>
     */
    public static function keys(object $u): array
    {
        $keys = [];
        $email = mb_strtolower(trim((string) $u->email));
        if ($email !== '' && str_contains($email, '@')) {
            $keys[] = 'email:'.$email;
        }
        foreach ([$u->phone_e164 ?? null, $u->phone_number ?? null] as $phone) {
            $digits = preg_replace('/\D+/', '', (string) $phone);
            if (strlen($digits) >= 9) {
                $keys[] = 'phone:'.substr($digits, -9);
            }
        }

        return array_values(array_unique($keys));
    }
}

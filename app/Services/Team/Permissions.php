<?php

namespace App\Services\Team;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may do what inside a company (plan C5, P3-4). Role comes from the
 * membership (owner if the user owns the company); permissions are the role's
 * defaults with per-company overrides.
 */
class Permissions
{
    /** @var array<string, array{role: string, perms: array<int, string>}> */
    private static array $cache = [];

    public static function all(): array
    {
        return array_keys(config('permissions.permissions'));
    }

    public static function defaultsFor(string $role): array
    {
        $p = config("permissions.roles.{$role}.permissions", []);

        return in_array('*', $p, true) ? self::all() : $p;
    }

    public static function roleOf(User $user): string
    {
        return self::resolve($user)['role'];
    }

    public static function can(?User $user, string $permission): bool
    {
        return $user !== null && in_array($permission, self::resolve($user)['perms'], true);
    }

    /** @return array<int, string> */
    public static function of(User $user): array
    {
        return self::resolve($user)['perms'];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function resolve(User $user): array
    {
        $key = $user->id.':'.$user->company_id;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $company = Company::withoutGlobalScopes()->find($user->company_id);
        $member = DB::table('company_members')->where('company_id', $user->company_id)->where('user_id', $user->id)->first();
        if ($company && (int) $company->owner_id === (int) $user->id) {
            $role = 'owner';
        } elseif ($member && $member->status === 'active') {
            $role = match ($member->role) {
                'admin' => 'manager', // Ping Pin organisation admins
                'member' => 'viewer',
                default => array_key_exists($member->role, config('permissions.roles')) ? $member->role : 'viewer',
            };
        } elseif ($member) {
            $role = 'none'; // deactivated
        } else {
            $role = 'manager'; // pre-roles staff keep their access (DECISIONS E30)
        }
        $perms = $role === 'none' ? [] : self::defaultsFor($role);
        if ($role !== 'owner' && $role !== 'none') {
            foreach (DB::table('company_role_permissions')->where('company_id', $user->company_id)->where('role', $role)->get() as $o) {
                $perms = $o->allowed ? array_values(array_unique([...$perms, $o->permission])) : array_values(array_diff($perms, [$o->permission]));
            }
        }

        return self::$cache[$key] = ['role' => $role, 'perms' => $perms];
    }
}

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

    /**
     * The permissions a company is offered (and its owner holds): the family's, plus the permissions of the modules
     * it has. An account built around an exclusive module (a SACCO) keeps that module's and the account's own
     * (config permissions.exclusive_keeps), not the shop's. For a shop this is exactly the list it always had.
     *
     * @return list<string>
     */
    public static function availableFor(int $companyId): array
    {
        $modules = self::modulesOf($companyId);
        $byModule = (array) config('permissions.module_permissions', []);
        $exclusive = \App\Support\Rules\CompanyRules::exclusiveOf($modules);
        if ($exclusive !== null) {
            return array_values(array_unique([...(array) config('permissions.exclusive_keeps', []), ...(array) ($byModule[$exclusive] ?? [])]));
        }
        $off = [];
        foreach ($byModule as $module => $perms) {
            if (! in_array($module, $modules, true)) {
                $off = [...$off, ...$perms];
            }
        }

        return array_values(array_diff(self::all(), $off));
    }

    /**
     * The roles a company may give (owner first): roles without a module, or of a module it has; an exclusive
     * module's account gets that module's roles with the owner and the viewer only (04 §6.1).
     *
     * @return list<string>
     */
    public static function rolesFor(int $companyId): array
    {
        $modules = self::modulesOf($companyId);
        $exclusive = \App\Support\Rules\CompanyRules::exclusiveOf($modules);
        $out = [];
        foreach ((array) config('permissions.roles') as $key => $r) {
            $module = $r['module'] ?? null;
            if ($exclusive !== null ? ($module === $exclusive || in_array($key, ['owner', 'viewer'], true)) : ($module === null || in_array($module, $modules, true))) {
                $out[] = (string) $key;
            }
        }

        return $out;
    }

    /** @var array<int, list<string>> company id => its modules */
    private static array $modules = [];

    /** @return list<string> */
    private static function modulesOf(int $companyId): array
    {
        self::fresh();
        if (! array_key_exists($companyId, self::$modules)) {
            $company = Company::withoutGlobalScopes()->find($companyId);
            self::$modules[$companyId] = $company?->modules() ?? [];
        }

        return self::$modules[$companyId];
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
        self::$owners = [];
        self::$overrides = [];
        self::$modules = [];
    }

    /**
     * A role's permissions in one company: the role's defaults with the company's overrides.
     *
     * @return array<int, string>
     */
    public static function forRole(int $companyId, string $role): array
    {
        $available = self::availableFor($companyId);
        if ($role === 'owner') {
            return $available;
        }
        $perms = self::defaultsFor($role);
        if ($role === 'viewer' && in_array('sacco', self::modulesOf($companyId), true)) {
            $perms[] = 'sacco_view'; // a SACCO's viewer sees its books (04 §6.1)
        }
        foreach (self::overrides($companyId)[$role] ?? [] as $permission => $allowed) {
            $perms = $allowed ? array_values(array_unique([...$perms, $permission])) : array_values(array_diff($perms, [$permission]));
        }

        return array_values(array_intersect($perms, $available));
    }

    /** @var array<int, int|null> company id => owner user id */
    private static array $owners = [];

    /** @var array<int, array<string, array<string, bool>>> company id => role => permission => allowed */
    private static array $overrides = [];

    /** The container the memo belongs to: a new one (next test, next request in a worker) starts clean. */
    private static ?int $owner = null;

    /** @return array<string, array<string, bool>> */
    private static function overrides(int $companyId): array
    {
        self::fresh();
        if (! array_key_exists($companyId, self::$overrides)) {
            $out = [];
            foreach (DB::table('company_role_permissions')->where('company_id', $companyId)->orderBy('id')->get(['role', 'permission', 'allowed']) as $o) {
                $out[(string) $o->role][(string) $o->permission] = (bool) $o->allowed;
            }
            self::$overrides[$companyId] = $out;
        }

        return self::$overrides[$companyId];
    }

    /** A new container (next test, next job in a worker) starts clean, and so does every HTTP request. */
    private static function fresh(): void
    {
        $app = app();
        $id = spl_object_id($app);
        if (self::$owner !== $id) {
            self::$owner = $id;
            self::flush();
            $app['events']->listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, fn () => self::flush());
        }
    }

    /**
     * Memoised per request (plan A4): the company's owner and its role overrides are read once per
     * company, however many users are resolved (the Team screen resolves every member); each user's
     * membership is read once.
     */
    private static function resolve(User $user): array
    {
        self::fresh();
        if ($user->company_id === null && $user->id) {
            $user->company_id = DB::table('admin_users')->where('id', $user->id)->value('company_id'); // partial selects
        }
        $key = $user->id.':'.$user->company_id;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $cid = (int) $user->company_id;
        if (! array_key_exists($cid, self::$owners)) {
            $owner = Company::withoutGlobalScopes()->whereKey($cid)->value('owner_id');
            self::$owners[$cid] = $owner === null ? null : (int) $owner;
        }
        $member = DB::table('company_members')->where('company_id', $cid)->where('user_id', $user->id)->first();
        if (self::$owners[$cid] !== null && self::$owners[$cid] === (int) $user->id) {
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
        $perms = $role === 'none' ? [] : self::forRole($cid, $role);

        return self::$cache[$key] = ['role' => $role, 'perms' => $perms];
    }
}

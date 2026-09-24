<?php

namespace App\Http\Middleware;

use Closure;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-administration screens must never be reachable by tenant users.
 *
 * Defence in depth for P0-1 of SHOP_ONBOARDING_OFFLINE_MASTER_PLAN.md: the
 * data migration `scope_tenant_admin_permissions` removes the `*` permission
 * from tenant roles, and this middleware additionally hard-denies the
 * platform paths below regardless of what the permission tables say (so a
 * later accidental `*` grant can't re-open billing or user administration).
 *
 * Two ways to use it:
 *  - globally via config('admin.route.middleware'): only PLATFORM_PATHS are denied;
 *  - as route middleware `admin.platform` on a group: everything in the group is denied
 *    to non-platform users.
 */
class PlatformAdminOnly
{
    /** Role slugs that identify a platform (super) administrator. */
    public const PLATFORM_ROLES = ['admin', 'administrator'];

    /** Admin-panel paths (no leading slash) that only platform admins may open. */
    public const PLATFORM_PATHS = [
        'auth/users', 'auth/users/*',
        'auth/roles', 'auth/roles/*',
        'auth/permissions', 'auth/permissions/*',
        'auth/menu', 'auth/menu/*',
        'auth/logs', 'auth/logs/*',
        'companies', 'companies/*',
        'plans', 'plans/*',
        'subscriptions', 'subscriptions/*',
        'pingpin-plans', 'pingpin-plans/*',
        'gens', 'gens/*', 'gen', 'gen/*',
    ];

    public function handle(Request $request, Closure $next, string $mode = 'paths'): Response
    {
        $user = Admin::user();
        if ($user === null || self::isPlatformAdmin($user)) {
            return $next($request);
        }

        if ($mode === 'all' || $request->is(...self::PLATFORM_PATHS)) {
            abort(403, 'This area is reserved for platform administrators.');
        }

        return $next($request);
    }

    public static function isPlatformAdmin($user): bool
    {
        return $user !== null && method_exists($user, 'inRoles') && $user->inRoles(self::PLATFORM_ROLES);
    }
}

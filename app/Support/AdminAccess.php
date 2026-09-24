<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for laravel-admin roles, permissions and menu
 * visibility (P0-1). Used by the scoping migration, the AdminRolesSeeder
 * (fresh installs / test DB) and the PlatformAdminOnly middleware.
 */
class AdminAccess
{
    public const PLATFORM_ROLE = ['name' => 'Administrator', 'slug' => 'admin'];

    /** Tenant roles (global rows kept until per-company roles land in Phase 3, C5). */
    public const TENANT_ROLES = [
        ['name' => 'Company Owner', 'slug' => 'company'],
        ['name' => 'Company Worker', 'slug' => 'worker'],
        ['name' => 'super-treasurer', 'slug' => 'super-treasurer'],
        ['name' => 'treasurer', 'slug' => 'treasurer'],
    ];

    public const BASE_PERMISSIONS = [
        ['name' => 'All permission', 'slug' => '*', 'http_method' => '', 'http_path' => '*'],
        ['name' => 'Dashboard', 'slug' => 'dashboard', 'http_method' => 'GET', 'http_path' => '/'],
        ['name' => 'Login', 'slug' => 'auth.login', 'http_method' => '', 'http_path' => "/auth/login\n/auth/logout"],
        ['name' => 'User setting', 'slug' => 'auth.setting', 'http_method' => 'GET,PUT', 'http_path' => '/auth/setting'],
        ['name' => 'Auth management', 'slug' => 'auth.management', 'http_method' => '', 'http_path' => "/auth/roles\n/auth/permissions\n/auth/menu\n/auth/logs"],
    ];

    /** Everything a tenant user may open in the admin panel (laravel-admin http_path syntax). */
    public const TENANT_PATHS = [
        '/', '/auth/setting', '/auth/logout',
        '/companies-edit*', '/employees*',
        '/stock-categories*', '/stock-sub-categories*', '/stock-items*', '/stock-records*', '/sale-records*',
        '/financial-periods*', '/financial-categories*', '/financial-records*', '/financial-reports*',
        '/budget-programs*', '/budget-item-categories*', '/budget-items*', '/contribution-records*',
        '/handover-records*', '/data-exports*',
        '/purchase-orders*', '/inventory-forecasts*', '/auto-reorder-rules*',
        '/poultry-*',
        '/tracked-devices*', '/device-locations*', '/device-commands*', '/tracking-map*',
        '/api/products/quick-add', '/api/sales/quick-record', '/api/global-search',
        '/financial-report', '/budget-program-print', '/thanks', '/data-exports-print', '/sale-receipt-pdf', '/sale-invoice-pdf',
    ];

    public const TENANT_PERMISSION = ['name' => 'Tenant workspace', 'slug' => 'tenant.workspace'];

    /** Root menu titles and child uris visible to platform admins only. */
    public const PLATFORM_MENU_TITLES = ['Admin', 'Billing'];

    public const PLATFORM_MENU_URIS = ['pingpin-plans', 'plans', 'subscriptions'];

    /** Create the roles, base permissions and platform menus if they are missing. Idempotent. */
    public static function ensureBaseline(): void
    {
        $now = now();

        foreach (array_merge([self::PLATFORM_ROLE], self::TENANT_ROLES) as $role) {
            if (! DB::table('admin_roles')->where('slug', $role['slug'])->exists()) {
                DB::table('admin_roles')->insert($role + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        foreach (self::BASE_PERMISSIONS as $permission) {
            if (! DB::table('admin_permissions')->where('slug', $permission['slug'])->exists()) {
                DB::table('admin_permissions')->insert($permission + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        $platformRoleId = DB::table('admin_roles')->where('slug', self::PLATFORM_ROLE['slug'])->value('id');
        $allId = DB::table('admin_permissions')->where('slug', '*')->value('id');
        if (! DB::table('admin_role_permissions')->where('role_id', $platformRoleId)->where('permission_id', $allId)->exists()) {
            DB::table('admin_role_permissions')->insert(['role_id' => $platformRoleId, 'permission_id' => $allId, 'created_at' => $now, 'updated_at' => $now]);
        }

        self::ensureMenu('Dashboard', '/', 'fa-bar-chart');
        $adminId = self::ensureMenu('Admin', '', 'fa-tasks');
        foreach ([['Users', 'auth/users'], ['Roles', 'auth/roles'], ['Permission', 'auth/permissions'], ['Menu', 'auth/menu'], ['Operation log', 'auth/logs']] as [$title, $uri]) {
            self::ensureMenu($title, $uri, 'fa-user', $adminId);
        }
        $billingId = self::ensureMenu('Billing', '', 'fa-credit-card');
        self::ensureMenu('Plans', 'plans', 'fa-money', $billingId);
        self::ensureMenu('Subscriptions', 'subscriptions', 'fa-refresh', $billingId);
    }

    /** Replace `*` on tenant roles with the explicit allow-list and pin platform menus. Idempotent. */
    public static function scopeTenantRoles(): void
    {
        $now = now();

        $tenantPermissionId = DB::table('admin_permissions')->where('slug', self::TENANT_PERMISSION['slug'])->value('id');
        if (! $tenantPermissionId) {
            $tenantPermissionId = DB::table('admin_permissions')->insertGetId(self::TENANT_PERMISSION + [
                'http_method' => '',
                'http_path' => implode("\n", self::TENANT_PATHS),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('admin_permissions')->where('id', $tenantPermissionId)->update(['http_path' => implode("\n", self::TENANT_PATHS), 'updated_at' => $now]);
        }

        $allId = DB::table('admin_permissions')->where('slug', '*')->value('id');
        $grantIds = DB::table('admin_permissions')->whereIn('slug', ['dashboard', 'auth.setting', 'auth.login'])->pluck('id')->push($tenantPermissionId);
        $tenantRoleIds = DB::table('admin_roles')->whereIn('slug', array_column(self::TENANT_ROLES, 'slug'))->pluck('id');

        foreach ($tenantRoleIds as $roleId) {
            if ($allId) {
                DB::table('admin_role_permissions')->where('role_id', $roleId)->where('permission_id', $allId)->delete();
            }
            foreach ($grantIds as $pid) {
                if (! DB::table('admin_role_permissions')->where('role_id', $roleId)->where('permission_id', $pid)->exists()) {
                    DB::table('admin_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $pid, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        $platformRoleId = DB::table('admin_roles')->where('slug', self::PLATFORM_ROLE['slug'])->value('id');
        if ($platformRoleId) {
            foreach (self::platformMenuIds() as $menuId) {
                if (! DB::table('admin_role_menu')->where('role_id', $platformRoleId)->where('menu_id', $menuId)->exists()) {
                    DB::table('admin_role_menu')->insert(['role_id' => $platformRoleId, 'menu_id' => $menuId, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    /** Reverse of scopeTenantRoles() (used by the migration's down()). */
    public static function unscopeTenantRoles(): void
    {
        $tenantPermissionId = DB::table('admin_permissions')->where('slug', self::TENANT_PERMISSION['slug'])->value('id');
        $allId = DB::table('admin_permissions')->where('slug', '*')->value('id');
        $tenantRoleIds = DB::table('admin_roles')->whereIn('slug', array_column(self::TENANT_ROLES, 'slug'))->pluck('id');

        foreach ($tenantRoleIds as $roleId) {
            if ($tenantPermissionId) {
                DB::table('admin_role_permissions')->where('role_id', $roleId)->where('permission_id', $tenantPermissionId)->delete();
            }
            if ($allId && ! DB::table('admin_role_permissions')->where('role_id', $roleId)->where('permission_id', $allId)->exists()) {
                DB::table('admin_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $allId]);
            }
        }
        if ($tenantPermissionId) {
            DB::table('admin_permissions')->where('id', $tenantPermissionId)->delete();
        }

        $platformRoleId = DB::table('admin_roles')->where('slug', self::PLATFORM_ROLE['slug'])->value('id');
        if ($platformRoleId) {
            $adminMenuId = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Admin')->value('id');
            DB::table('admin_role_menu')->where('role_id', $platformRoleId)->whereIn('menu_id', self::platformMenuIds())->where('menu_id', '!=', $adminMenuId)->delete();
        }
    }

    public static function platformMenuIds(): array
    {
        return DB::table('admin_menu')
            ->where(function ($q) {
                $q->where(function ($q) {
                    $q->where('parent_id', 0)->whereIn('title', self::PLATFORM_MENU_TITLES);
                })->orWhereIn('uri', self::PLATFORM_MENU_URIS);
            })
            ->pluck('id')
            ->all();
    }

    private static function ensureMenu(string $title, string $uri, string $icon, int $parentId = 0): int
    {
        $existing = DB::table('admin_menu')->where('parent_id', $parentId)->where('title', $title)->value('id');
        if ($existing) {
            return (int) $existing;
        }
        $order = (int) DB::table('admin_menu')->max('order') + 1;

        return (int) DB::table('admin_menu')->insertGetId([
            'parent_id' => $parentId, 'order' => $order, 'title' => $title, 'icon' => $icon, 'uri' => $uri,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

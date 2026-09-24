<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;

/**
 * P0-1: tenant users must not reach platform administration screens, and
 * must still reach their own workspace.
 */
class PlatformAdminScopingTest extends AdminTestCase
{
    public function test_tenant_roles_no_longer_hold_the_wildcard_permission(): void
    {
        $star = DB::table('admin_permissions')->where('slug', '*')->value('id');
        $tenantRoleIds = DB::table('admin_roles')->whereIn('slug', ['company', 'worker', 'super-treasurer', 'treasurer'])->pluck('id');

        $this->assertSame(0, DB::table('admin_role_permissions')->whereIn('role_id', $tenantRoleIds)->where('permission_id', $star)->count());
        $this->assertSame(1, DB::table('admin_role_permissions')->where('role_id', DB::table('admin_roles')->where('slug', 'admin')->value('id'))->where('permission_id', $star)->count());
    }

    public function test_company_owner_cannot_open_platform_screens(): void
    {
        $owner = $this->makeTenant('company')['user'];

        foreach (['/subscriptions', '/plans', '/pingpin-plans', '/companies', '/auth/users', '/auth/roles', '/auth/permissions', '/auth/menu', '/gens'] as $path) {
            $this->asAdmin($owner)->get($path)->assertStatus(403);
        }
    }

    public function test_worker_cannot_open_platform_screens_either(): void
    {
        $worker = $this->makeTenant('worker')['user'];

        $this->asAdmin($worker)->get('/subscriptions')->assertStatus(403);
        $this->asAdmin($worker)->get('/auth/users')->assertStatus(403);
    }

    public function test_company_owner_still_reaches_the_tenant_workspace(): void
    {
        $owner = $this->makeTenant('company')['user'];

        foreach (['/stock-items', '/sale-records', '/companies-edit', '/budget-programs', '/employees'] as $path) {
            $this->asAdmin($owner)->get($path)->assertOk();
        }
    }

    public function test_platform_admin_can_open_platform_screens(): void
    {
        $admin = $this->makeTenant('admin')['user'];

        $this->asAdmin($admin)->get('/subscriptions')->assertOk();
        $this->asAdmin($admin)->get('/plans')->assertOk();
        $this->asAdmin($admin)->get('/companies')->assertOk();
        $this->asAdmin($admin)->get('/auth/users')->assertOk();
    }

    public function test_platform_menus_are_pinned_to_the_administrator_role(): void
    {
        $adminRole = DB::table('admin_roles')->where('slug', 'admin')->value('id');
        $billing = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Billing')->value('id');
        $pingPinPlans = DB::table('admin_menu')->where('uri', 'pingpin-plans')->value('id');

        $this->assertTrue(DB::table('admin_role_menu')->where('role_id', $adminRole)->where('menu_id', $billing)->exists());
        if ($pingPinPlans) {
            $this->assertTrue(DB::table('admin_role_menu')->where('role_id', $adminRole)->where('menu_id', $pingPinPlans)->exists());
        }
        $this->assertSame(1, DB::table('admin_role_menu')->where('menu_id', $billing)->count());
    }
}

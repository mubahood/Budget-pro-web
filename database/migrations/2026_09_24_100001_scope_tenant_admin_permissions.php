<?php

use App\Support\AdminAccess;
use Illuminate\Database\Migrations\Migration;

/**
 * P0-1 (SHOP_ONBOARDING_OFFLINE_MASTER_PLAN.md §C9): every admin role held the
 * `*` permission, so any tenant user could open the global Users/Roles/Menu
 * screens and the Billing (Plans/Subscriptions) screens. Tenant roles now get an
 * explicit allow-list permission instead, and platform-only menus are pinned to
 * the Administrator role. Logic lives in App\Support\AdminAccess (shared with
 * AdminRolesSeeder). Idempotent; keyed by slug, not id.
 */
return new class extends Migration
{
    public function up(): void
    {
        AdminAccess::scopeTenantRoles();
    }

    public function down(): void
    {
        AdminAccess::unscopeTenantRoles();
    }
};

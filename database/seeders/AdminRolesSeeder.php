<?php

namespace Database\Seeders;

use App\Support\AdminAccess;
use Illuminate\Database\Seeder;

/**
 * Roles, base permissions, platform menus and the tenant allow-list.
 * Idempotent: safe on production, on a fresh install, and inside a test transaction.
 */
class AdminRolesSeeder extends Seeder
{
    public function run(): void
    {
        AdminAccess::ensureBaseline();
        AdminAccess::scopeTenantRoles();
    }
}

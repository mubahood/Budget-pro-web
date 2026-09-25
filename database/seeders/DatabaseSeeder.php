<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database (fresh installs and CI).
     */
    public function run(): void
    {
        $this->call([
            AdminRolesSeeder::class,       // roles, permissions, platform + tenant menus
            PlanSeeder::class,             // paid plans (the Free plan comes with its migration)
            PingPinPlanSeeder::class,
            ProductTemplateSeeder::class,  // template packs for the setup wizard
            PlatformAdminSeeder::class,    // first platform admin from ADMIN_EMAIL / ADMIN_PASSWORD
        ]);
        // Per-shop roles and their menus (plan C5).
        \App\Support\AdminAccess::ensureShopRoles();
    }
}

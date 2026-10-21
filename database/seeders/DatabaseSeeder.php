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
        // A standalone copy gets only its reference data: no SaaS plans, no platform admin, no demo
        // (docs/STANDALONE_EDITION_PLAN.md §7.1). Its one business is created by the installer.
        if (\App\Support\Edition::isStandalone()) {
            $this->call(StandaloneReferenceSeeder::class);

            return;
        }

        $this->call([
            AdminRolesSeeder::class,       // roles, permissions, platform + tenant menus
            PlanSeeder::class,             // paid plans (the Free plan comes with its migration)
            AkibaPlanSeeder::class,        // Akiba Ledger's own plan line (akiba-*, AK-15)
            PingPinPlanSeeder::class,
            ProductTemplateSeeder::class,  // template packs for the setup wizard
            PlatformAdminSeeder::class,    // first platform admin from ADMIN_EMAIL / ADMIN_PASSWORD
        ]);
        // Per-shop roles and their menus (plan C5).
        \App\Support\AdminAccess::ensureShopRoles();
    }
}

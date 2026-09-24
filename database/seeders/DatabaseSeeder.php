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
            AdminRolesSeeder::class,
            PlanSeeder::class,
            PingPinPlanSeeder::class,
        ]);
    }
}

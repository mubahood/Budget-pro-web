<?php

use Database\Seeders\ProductTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Template packs priced for Kenya, Tanzania and Rwanda too (POWER_PLAN §3.1), plus the new packs
 * for poultry farms and "other" businesses. Data only; re-runnable (the seeder upserts by name).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_templates')) {
            (new ProductTemplateSeeder())->run();
        }
    }

    public function down(): void
    {
        // Data only: older packs keep working with the extra currencies present.
    }
};

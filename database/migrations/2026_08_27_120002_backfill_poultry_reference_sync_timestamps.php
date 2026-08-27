<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Farm types / production guide tasks got client_updated_at/version
     * columns in Phase 9 (§9.2) but nothing ever populated them — a null
     * client_updated_at would never match a pull's `> since` filter once
     * since > 0, permanently hiding these rows from every mobile client.
     */
    public function up(): void
    {
        foreach (['poultry_farm_types', 'poultry_production_guide_tasks'] as $table) {
            DB::statement("update `{$table}` set client_updated_at = unix_timestamp(updated_at) * 1000 where client_updated_at is null");
        }
    }

    public function down(): void
    {
        // No-op — this only fills in previously-null timestamps.
    }
};

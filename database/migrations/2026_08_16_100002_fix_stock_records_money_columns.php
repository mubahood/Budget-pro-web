<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens stock_records money/quantity columns from double(8,2) — which overflows
 * at 999,999.99 (a real risk in UGX where single items sell for hundreds of
 * thousands) — to decimal(20,2). Uses raw ALTER so no doctrine/dbal dependency
 * is required.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_records')) {
            return;
        }

        DB::statement('ALTER TABLE `stock_records`
            MODIFY `quantity` DECIMAL(20,2) NOT NULL DEFAULT 0,
            MODIFY `selling_price` DECIMAL(20,2) NOT NULL DEFAULT 0,
            MODIFY `total_sales` DECIMAL(20,2) NOT NULL DEFAULT 0');

        // profit is bigint in some installs; normalise to decimal(20,2) too.
        if (Schema::hasColumn('stock_records', 'profit')) {
            DB::statement('ALTER TABLE `stock_records` MODIFY `profit` DECIMAL(20,2) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_records')) {
            return;
        }

        DB::statement('ALTER TABLE `stock_records`
            MODIFY `quantity` DOUBLE(8,2) NOT NULL DEFAULT 0,
            MODIFY `selling_price` DOUBLE(8,2) NOT NULL DEFAULT 0,
            MODIFY `total_sales` DOUBLE(8,2) NOT NULL DEFAULT 0');
    }
};

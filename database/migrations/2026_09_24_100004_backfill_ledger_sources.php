<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tag historical, system-posted ledger rows so dashboards can tell sales income
 * from manual income without double counting (P0-7/P0-9). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE financial_records SET source_type = 'stock_record', source_id = CAST(SUBSTRING(description, 11) AS UNSIGNED) WHERE source_type IS NULL AND description LIKE 'Sales of #%'");
        DB::statement("UPDATE sale_records SET status = 'Completed' WHERE status = 'completed'");
        DB::statement("UPDATE sale_records SET payment_status = 'Unpaid' WHERE payment_status IS NULL OR payment_status = ''");
    }

    public function down(): void
    {
        DB::statement("UPDATE financial_records SET source_type = NULL, source_id = NULL WHERE source_type = 'stock_record' AND description LIKE 'Sales of #%'");
    }
};

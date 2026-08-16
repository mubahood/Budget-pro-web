<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds company_id indexes to every tenant-scoped table that lacks one.
 *
 * CompanyScope appends "WHERE company_id = ?" to every query on these tables,
 * so without an index the whole application does full table scans. Index
 * creation is idempotent (skips tables/indexes that already exist).
 */
return new class extends Migration
{
    private array $tables = [
        'stock_items', 'stock_records', 'stock_categories', 'stock_sub_categories',
        'financial_records', 'financial_categories', 'financial_reports',
        'budget_programs', 'budget_items', 'budget_item_categories',
        'contribution_records', 'handover_records', 'data_exports', 'admin_users',
    ];

    public function up(): void
    {
        $database = DB::getDatabaseName();

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            $indexName = "idx_{$table}_company_id";

            $exists = DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->exists();

            if ($exists) {
                continue;
            }

            Schema::table($table, function ($t) use ($indexName) {
                $t->index('company_id', $indexName);
            });
        }
    }

    public function down(): void
    {
        $database = DB::getDatabaseName();

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $indexName = "idx_{$table}_company_id";

            $exists = DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->exists();

            if ($exists) {
                Schema::table($table, function ($t) use ($indexName) {
                    $t->dropIndex($indexName);
                });
            }
        }
    }
};

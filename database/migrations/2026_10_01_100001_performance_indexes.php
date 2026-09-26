<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries every shop screen runs (docs/POWER_PLAN.md §1, A1). EXPLAIN showed full
 * table scans over every shop's rows for:
 * - SalesSource's "not reversed" check (stock_records.reverses_id);
 * - a product's movement timeline and the Movements date filter (stock_records);
 * - the till's category filter (stock_items);
 * - the Money date filter (financial_records);
 * - returns and payments by day (sale_returns, payments).
 *
 * Idempotent and MySQL 5.7 safe: an index is skipped when one with the same name, or any index
 * starting with the same columns in the same order, already exists (payments already has one).
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: array<int, string>}> [table, index name, columns] */
    private array $indexes = [
        ['stock_records', 'stock_records_reverses_idx', ['reverses_id']],
        ['stock_records', 'stock_records_company_item_idx', ['company_id', 'stock_item_id', 'id']],
        ['stock_records', 'stock_records_company_created_idx', ['company_id', 'created_at']],
        ['stock_items', 'stock_items_company_category_idx', ['company_id', 'stock_category_id']],
        ['financial_records', 'financial_records_company_deleted_date_idx', ['company_id', 'is_deleted', 'date']],
        ['sale_returns', 'sale_returns_company_created_idx', ['company_id', 'created_at']],
        ['payments', 'payments_company_received_at_idx', ['company_id', 'received_at']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $name, $columns]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns) || $this->covered($table, $name, $columns)) {
                continue;
            }
            Schema::table($table, fn ($t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, $name]) {
            if (Schema::hasTable($table) && in_array($name, array_keys($this->existing($table)), true)) {
                Schema::table($table, fn ($t) => $t->dropIndex($name));
            }
        }
    }

    /** True when the index exists by name, or another index already starts with these columns. */
    private function covered(string $table, string $name, array $columns): bool
    {
        foreach ($this->existing($table) as $index => $cols) {
            if ($index === $name || array_slice($cols, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<int, string>> index name => columns in order */
    private function existing(string $table): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return [];
        }
        $out = [];
        $rows = DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)
            ->orderBy('index_name')->orderBy('seq_in_index')->get(['index_name', 'column_name']);
        foreach ($rows as $r) {
            $r = (array) $r;
            $r = array_change_key_case($r, CASE_LOWER);
            $out[(string) $r['index_name']][] = (string) $r['column_name'];
        }

        return $out;
    }
};

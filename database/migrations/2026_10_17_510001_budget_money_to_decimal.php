<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pledges and budgets in exact decimals (roadmap 02 §6, 04 §4; DECISIONS G11): every money column of the
 * budget module becomes DECIMAL(20,2) and the item quantity DECIMAL(15,3). They were BIGINT, so the
 * change keeps every value exactly (whole numbers fit), and totals are unchanged. Percentages keep their
 * two decimals (they were cut to whole numbers).
 */
return new class extends Migration
{
    /** table => [column => [type, default]] */
    private const COLUMNS = [
        'budget_programs' => [
            'total_collected' => ['DECIMAL(20,2)', '0'], 'total_expected' => ['DECIMAL(20,2)', '0'], 'total_in_pledge' => ['DECIMAL(20,2)', '0'],
            'budget_total' => ['DECIMAL(20,2)', '0'], 'budget_spent' => ['DECIMAL(20,2)', '0'], 'budget_balance' => ['DECIMAL(20,2)', '0'],
        ],
        'budget_item_categories' => [
            'target_amount' => ['DECIMAL(20,2)', null], 'invested_amount' => ['DECIMAL(20,2)', null], 'balance' => ['DECIMAL(20,2)', null],
            'percentage_done' => ['DECIMAL(10,2)', null],
        ],
        'budget_items' => [
            'target_amount' => ['DECIMAL(20,2)', null], 'invested_amount' => ['DECIMAL(20,2)', null], 'balance' => ['DECIMAL(20,2)', null],
            'unit_price' => ['DECIMAL(20,2)', null], 'quantity' => ['DECIMAL(15,3)', null], 'percentage_done' => ['DECIMAL(10,2)', null],
        ],
        'contribution_records' => [
            'amount' => ['DECIMAL(20,2)', '0'], 'paid_amount' => ['DECIMAL(20,2)', '0'], 'not_paid_amount' => ['DECIMAL(20,2)', '0'],
        ],
    ];

    public function up(): void
    {
        $this->alter(fn (string $type) => $type);
    }

    public function down(): void
    {
        // Back to whole numbers (rounded): only for rolling back this release.
        $this->alter(fn (string $type) => 'BIGINT');
    }

    private function alter(callable $typeFor): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $parts = [];
            foreach ($columns as $column => [$type, $default]) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $parts[] = "MODIFY `{$column}` ".$typeFor($type).' NULL'.($default !== null ? " DEFAULT {$default}" : '');
            }
            if ($parts !== []) {
                DB::statement("ALTER TABLE `{$table}` ".implode(', ', $parts));
            }
        }
    }
};

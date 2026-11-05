<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PB-40: an event's pledges, budget lines and categories read through an index instead of every row of the account.
 * Additive and safe to run twice.
 */
return new class extends Migration
{
    private const INDEXES = [
        'contribution_records' => 'contribution_records_program_deleted_idx',
        'budget_items' => 'budget_items_program_deleted_idx',
        'budget_item_categories' => 'budget_item_categories_program_deleted_idx',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if (! $this->hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $t) => $t->index(['budget_program_id', 'is_deleted'], $index));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if ($this->hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $i) => $i['name'] === $index);
    }
};

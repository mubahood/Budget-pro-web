<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PB-35: a line's unit ("3 tiers × UGX 300,000"), and the order someone put categories and lines in. New nullable columns only: with no order set, the
 * biggest money comes first. Old details ("unit: tier, from template x") are read apart at display time and not rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_items', function (Blueprint $t) {
            if (! Schema::hasColumn('budget_items', 'unit')) {
                $t->string('unit', 30)->nullable();
            }
            if (! Schema::hasColumn('budget_items', 'sort_order')) {
                $t->unsignedInteger('sort_order')->nullable();
            }
        });
        Schema::table('budget_item_categories', function (Blueprint $t) {
            if (! Schema::hasColumn('budget_item_categories', 'sort_order')) {
                $t->unsignedInteger('sort_order')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['budget_items' => ['unit', 'sort_order'], 'budget_item_categories' => ['sort_order']] as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($table, $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->dropColumn($column);
                    }
                }
            });
        }
    }
};

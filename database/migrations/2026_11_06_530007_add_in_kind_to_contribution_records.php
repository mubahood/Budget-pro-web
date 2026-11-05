<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PB-39: "I'll bring the cake" and "two goats". An in-kind pledge keeps amount = 0, so it can never enter Pledged,
 * Collected or Still owed on any client; what it is and its value sit in their own columns. A budget line can be
 * ticked as covered by one, which is the only way it lowers Still to find. New nullable columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contribution_records', function (Blueprint $t) {
            if (! Schema::hasColumn('contribution_records', 'kind')) {
                $t->string('kind', 20)->nullable(); // null or money = a money pledge; in_kind
            }
            if (! Schema::hasColumn('contribution_records', 'in_kind_what')) {
                $t->string('in_kind_what', 191)->nullable();
            }
            if (! Schema::hasColumn('contribution_records', 'in_kind_value')) {
                $t->decimal('in_kind_value', 20, 2)->nullable();
            }
        });
        if (! Schema::hasColumn('budget_items', 'covered_by_record_id')) {
            Schema::table('budget_items', fn (Blueprint $t) => $t->unsignedBigInteger('covered_by_record_id')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('contribution_records', function (Blueprint $t) {
            foreach (['kind', 'in_kind_what', 'in_kind_value'] as $column) {
                if (Schema::hasColumn('contribution_records', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
        if (Schema::hasColumn('budget_items', 'covered_by_record_id')) {
            Schema::table('budget_items', fn (Blueprint $t) => $t->dropColumn('covered_by_record_id'));
        }
    }
};

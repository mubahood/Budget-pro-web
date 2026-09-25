<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('budget_items') || Schema::hasColumn('budget_items', 'approved')) {
            return; // applied before the column was tracked by migrations
        }
        Schema::table('budget_items', function (Blueprint $table) {
            $table->string('approved')->nullable()->default('No');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budget_items', function (Blueprint $table) {
            //
        });
    }
};

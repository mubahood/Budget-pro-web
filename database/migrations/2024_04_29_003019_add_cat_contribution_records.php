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
        if (! Schema::hasTable('contribution_records') || Schema::hasColumn('contribution_records', 'category_id')) {
            return; // applied before the column was tracked by migrations
        }
        Schema::table('contribution_records', function (Blueprint $table) {
            $table->string('category_id')->nullable()->default('Family');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contribution_records', function (Blueprint $table) {
            //
        });
    }
};

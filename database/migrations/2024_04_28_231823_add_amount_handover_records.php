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
        if (! Schema::hasTable('handover_records') || Schema::hasColumn('handover_records', 'amount')) {
            return; // applied before the column was tracked by migrations
        }
        Schema::table('handover_records', function (Blueprint $table) {
            $table->bigInteger('amount')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('handover_records', function (Blueprint $table) {
            //
        });
    }
};

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
        Schema::table('stock_records', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_records', 'sale_record_id')) {
                $table->foreignId('sale_record_id')->nullable()->after('id')->constrained('sale_records')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_records', function (Blueprint $table) {
            if (Schema::hasColumn('stock_records', 'sale_record_id')) {
                $table->dropForeign(['sale_record_id']);
                $table->dropColumn('sale_record_id');
            }
        });
    }
};

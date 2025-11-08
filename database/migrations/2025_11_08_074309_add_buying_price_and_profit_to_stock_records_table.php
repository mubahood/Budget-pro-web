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
            // Add buying_price column only if it doesn't exist
            if (!Schema::hasColumn('stock_records', 'buying_price')) {
                $table->decimal('buying_price', 15, 2)->nullable()->after('selling_price');
            }
            
            // Add financial_period_id if it doesn't exist
            if (!Schema::hasColumn('stock_records', 'financial_period_id')) {
                $table->foreignId('financial_period_id')->nullable()->after('stock_sub_category_id');
            }
            
            // Add date column if it doesn't exist
            if (!Schema::hasColumn('stock_records', 'date')) {
                $table->date('date')->nullable()->after('total_sales');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_records', function (Blueprint $table) {
            // Only drop buying_price if it exists (profit was already there)
            if (Schema::hasColumn('stock_records', 'buying_price')) {
                $table->dropColumn('buying_price');
            }
            
            // Only drop if they exist
            if (Schema::hasColumn('stock_records', 'financial_period_id')) {
                $table->dropColumn('financial_period_id');
            }
            
            if (Schema::hasColumn('stock_records', 'date')) {
                $table->dropColumn('date');
            }
        });
    }
};

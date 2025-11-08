<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add composite index to stock_items for optimized dropdown queries
        Schema::table('stock_items', function (Blueprint $table) {
            // Index for category join and filtering by company and stock availability
            // $table->index(['company_id', 'current_quantity', 'stock_category_id'], 'idx_stock_items_dropdown');
        });
        
        // Add raw index for name field (TEXT column requires length specification)
        // DB::statement('CREATE INDEX idx_stock_items_search ON stock_items (company_id, name(100))');

        // Add indexes to sale_records for better grid performance
        Schema::table('sale_records', function (Blueprint $table) {
            // Index for filtering by company and date
            $table->index(['company_id', 'sale_date'], 'idx_sale_records_date');
            
            // Index for filtering by payment status
            $table->index(['company_id', 'payment_status'], 'idx_sale_records_payment');
            
            // Index for filtering by status
            $table->index(['company_id', 'status'], 'idx_sale_records_status');
            
            // Index for created_at ordering (already has DESC in query)
            $table->index(['company_id', 'created_at'], 'idx_sale_records_created');
        });

        // Add index to sale_record_items for counting
        Schema::table('sale_record_items', function (Blueprint $table) {
            $table->index('sale_record_id', 'idx_sale_record_items_count');
        });

        // Add index to financial_periods for quick active period lookup
        Schema::table('financial_periods', function (Blueprint $table) {
            $table->index(['company_id', 'status'], 'idx_financial_periods_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            // $table->dropIndex('idx_stock_items_dropdown');
        });
        
        DB::statement('DROP INDEX idx_stock_items_search ON stock_items');

        Schema::table('sale_records', function (Blueprint $table) {
            $table->dropIndex('idx_sale_records_date');
            $table->dropIndex('idx_sale_records_payment');
            $table->dropIndex('idx_sale_records_status');
            $table->dropIndex('idx_sale_records_created');
        });

        Schema::table('sale_record_items', function (Blueprint $table) {
            $table->dropIndex('idx_sale_record_items_count');
        });

        Schema::table('financial_periods', function (Blueprint $table) {
            $table->dropIndex('idx_financial_periods_active');
        });
    }
};

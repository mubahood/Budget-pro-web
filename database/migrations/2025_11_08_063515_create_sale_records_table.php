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
        // Main sale records table
        Schema::create('sale_records', function (Blueprint $table) {
            $table->id();
            
            // Foreign Keys
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('financial_period_id');
            $table->unsignedBigInteger('created_by_id');
            
            // Sale Date
            $table->date('sale_date');
            
            // Customer Information
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();
            
            // Financial Details
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            
            // Payment Information
            $table->string('payment_method')->default('Cash'); // Cash, Credit Card, Bank Transfer, Mobile Money
            $table->string('payment_status')->default('Unpaid'); // Paid, Unpaid, Partial
            
            // Sale Status
            $table->string('status')->default('Completed'); // Completed, Pending, Cancelled
            
            // Receipt & Invoice
            $table->string('receipt_number')->unique();
            $table->string('receipt_pdf_url')->nullable();
            $table->string('receipt_pdf_is_generated')->default('No'); // Yes, No
            
            $table->string('invoice_number')->unique();
            $table->string('invoice_pdf_url')->nullable();
            $table->string('invoice_pdf_is_generated')->default('No'); // Yes, No
            
            // Additional Notes
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('company_id');
            $table->index('financial_period_id');
            $table->index('created_by_id');
            $table->index('sale_date');
            $table->index('receipt_number');
            $table->index('invoice_number');
            $table->index('payment_status');
            $table->index('status');
        });
        
        // Pivot table for sale items
        Schema::create('sale_record_items', function (Blueprint $table) {
            $table->id();
            
            // Foreign Keys
            $table->unsignedBigInteger('sale_record_id');
            $table->unsignedBigInteger('stock_item_id');
            $table->unsignedBigInteger('stock_record_id')->nullable(); // Links to the StockRecord created
            
            // Item Details
            $table->string('item_name'); // Snapshot at time of sale
            $table->string('item_sku')->nullable(); // Snapshot at time of sale
            
            // Quantity & Pricing
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('subtotal', 15, 2);
            
            // Cost & Profit (for reporting)
            $table->decimal('unit_cost', 15, 2)->default(0); // Buying price at time of sale
            $table->decimal('profit', 15, 2)->default(0); // Calculated profit per item
            
            $table->timestamps();
            
            // Indexes
            $table->index('sale_record_id');
            $table->index('stock_item_id');
            $table->index('stock_record_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_record_items');
        Schema::dropIfExists('sale_records');
    }
};

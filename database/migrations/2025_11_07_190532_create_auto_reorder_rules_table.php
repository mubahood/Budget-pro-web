<?php

use App\Models\Company;
use App\Models\StockItem;
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
        Schema::create('auto_reorder_rules', function (Blueprint $table) {
            $table->id();
            
            // Foreign keys
            $table->foreignIdFor(Company::class);
            $table->foreignIdFor(StockItem::class);
            
            // Rule configuration
            $table->boolean('is_enabled')->default(true);
            $table->string('rule_name');
            
            // Reorder triggers
            $table->integer('reorder_point')->default(0); // Trigger when stock reaches this level
            $table->integer('reorder_quantity')->default(0); // Quantity to order
            $table->integer('min_stock_level')->default(0); // Minimum stock to maintain
            $table->integer('max_stock_level')->default(0); // Maximum stock to maintain
            
            // Supplier preferences
            $table->string('preferred_supplier_name')->nullable();
            $table->string('preferred_supplier_email')->nullable();
            $table->string('preferred_supplier_phone')->nullable();
            $table->text('preferred_supplier_address')->nullable();
            $table->decimal('preferred_unit_price', 15, 2)->default(0);
            $table->integer('lead_time_days')->default(7); // Expected delivery time
            
            // Forecasting integration
            $table->boolean('use_forecasting')->default(true); // Use forecast data for reorder decisions
            $table->string('forecast_algorithm')->default('moving_average'); // Algorithm to use
            $table->integer('forecast_horizon_days')->default(30); // Days ahead to forecast
            
            // Advanced rules
            $table->enum('reorder_method', ['fixed_quantity', 'economic_order_quantity', 'forecast_based'])->default('fixed_quantity');
            $table->decimal('holding_cost_percentage', 5, 2)->default(20.00); // Annual holding cost %
            $table->decimal('ordering_cost', 10, 2)->default(0); // Cost per order
            
            // Approval settings
            $table->boolean('requires_approval')->default(true);
            $table->decimal('auto_approve_threshold', 15, 2)->nullable(); // Auto-approve if total below this
            
            // Schedule
            $table->enum('check_frequency', ['hourly', 'daily', 'weekly'])->default('daily');
            $table->time('check_time')->default('09:00:00'); // Time to run daily checks
            $table->json('check_days')->nullable(); // Days of week for weekly checks
            
            // Notifications
            $table->boolean('send_email_notification')->default(true);
            $table->json('notification_emails')->nullable(); // Additional emails to notify
            
            // Status tracking
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('times_triggered')->default(0);
            
            // Additional settings
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('company_id');
            $table->index('stock_item_id');
            $table->index('is_enabled');
            $table->index(['stock_item_id', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auto_reorder_rules');
    }
};

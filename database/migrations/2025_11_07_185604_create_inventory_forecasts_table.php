<?php

use App\Models\Company;
use App\Models\StockItem;
use App\Models\FinancialPeriod;
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
        Schema::create('inventory_forecasts', function (Blueprint $table) {
            $table->id();
            
            // Foreign keys
            $table->foreignIdFor(Company::class);
            $table->foreignIdFor(StockItem::class);
            $table->foreignIdFor(FinancialPeriod::class)->nullable();
            
            // Forecast period
            $table->date('forecast_date'); // The date this forecast is for
            $table->string('forecast_period')->default('monthly'); // daily, weekly, monthly, quarterly
            
            // Historical data analysis
            $table->integer('historical_average')->default(0); // Average demand over historical period
            $table->integer('historical_min')->default(0);
            $table->integer('historical_max')->default(0);
            $table->decimal('standard_deviation', 10, 2)->default(0);
            
            // Forecast predictions
            $table->integer('predicted_demand')->default(0); // Forecasted demand
            $table->integer('predicted_min')->default(0); // Lower bound
            $table->integer('predicted_max')->default(0); // Upper bound
            $table->decimal('confidence_level', 5, 2)->default(0); // Confidence percentage (0-100)
            
            // Trend analysis
            $table->enum('trend', ['increasing', 'stable', 'decreasing', 'seasonal', 'volatile'])->default('stable');
            $table->decimal('trend_percentage', 10, 2)->default(0); // % change from historical
            
            // Seasonal patterns
            $table->boolean('is_seasonal')->default(false);
            $table->json('seasonal_factors')->nullable(); // Monthly/seasonal multipliers
            
            // Reorder recommendations
            $table->integer('recommended_reorder_point')->default(0);
            $table->integer('recommended_order_quantity')->default(0);
            $table->integer('safety_stock')->default(0);
            
            // Current stock situation
            $table->integer('current_stock')->default(0);
            $table->integer('days_until_stockout')->nullable(); // Predicted days until out of stock
            $table->enum('stock_status', ['overstocked', 'optimal', 'low', 'critical', 'stockout'])->default('optimal');
            
            // Algorithm metadata
            $table->string('algorithm_used')->default('moving_average'); // moving_average, exponential_smoothing, linear_regression
            $table->json('algorithm_parameters')->nullable(); // Store algorithm-specific params
            $table->decimal('forecast_accuracy', 5, 2)->nullable(); // Historical accuracy if available
            
            // Notes and actions
            $table->text('notes')->nullable();
            $table->boolean('action_required')->default(false);
            $table->text('recommended_action')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('company_id');
            $table->index('stock_item_id');
            $table->index('forecast_date');
            $table->index('stock_status');
            $table->index(['stock_item_id', 'forecast_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_forecasts');
    }
};


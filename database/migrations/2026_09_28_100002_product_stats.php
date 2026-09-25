<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P4-3 (plan A7): per-product 7/30/90-day sales velocity, refreshed nightly; the base for reorder suggestions. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_stats')) {
            Schema::create('product_stats', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('stock_item_id')->unique();
                $t->decimal('sold_7', 15, 3)->default(0);
                $t->decimal('sold_30', 15, 3)->default(0);
                $t->decimal('sold_90', 15, 3)->default(0);
                $t->decimal('revenue_30', 20, 2)->default(0);
                $t->decimal('profit_30', 20, 2)->default(0);
                $t->decimal('avg_daily_30', 15, 3)->default(0);
                $t->timestamp('last_sold_at')->nullable();
                $t->decimal('days_of_cover', 10, 1)->nullable(); // on hand ÷ average daily sales
                $t->timestamp('computed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stats');
    }
};

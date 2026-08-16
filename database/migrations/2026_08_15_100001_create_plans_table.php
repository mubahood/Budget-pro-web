<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('interval')->default('month'); // month | year | lifetime
            $table->unsignedInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true); // shown on pricing page
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('features')->nullable(); // feature flags: {"forecasting": true, ...}
            $table->json('limits')->nullable();   // quotas: {"max_users": 5, "max_stock_items": 500, ...}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same shape as budget-pro's own `plans` table (proven, live-verified
 * schema — PLAN.md §7) but a separate table entirely (DECISIONS.md D2):
 * Ping Pin's feature/limit vocabulary (device count, geofence count,
 * retention days, SMS fallback, ...) is unrelated to budget-pro's
 * (inventory/forecasting/...), and keeping them apart means a bug in one
 * product's billing can never corrupt the other's live paying customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingpin_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_ugx', 12, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('interval')->default('month'); // month | year | lifetime
            $table->unsignedInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingpin_plans');
    }
};

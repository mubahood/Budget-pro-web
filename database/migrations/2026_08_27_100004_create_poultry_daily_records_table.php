<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poultry_daily_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('batch_id')->index();
            $table->date('date');
            $table->unsignedInteger('eggs_trays')->default(0);
            $table->unsignedInteger('eggs_loose')->default(0);
            $table->unsignedInteger('mortality')->default(0);
            $table->decimal('feed_kg', 10, 2)->default(0);
            $table->decimal('water_l', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->decimal('egg_unit_price', 15, 2)->default(0);
            $table->decimal('feed_price_per_kg', 15, 2)->default(0);
            $table->decimal('avg_weight_kg', 8, 3)->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_daily_records');
    }
};

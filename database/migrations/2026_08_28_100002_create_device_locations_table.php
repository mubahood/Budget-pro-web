<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index(); // denormalized for direct tenant-scoped queries
            $table->unsignedBigInteger('device_id')->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('altitude_m', 8, 2)->nullable();
            $table->decimal('speed_mps', 8, 2)->nullable();
            $table->decimal('heading_deg', 6, 2)->nullable();
            $table->string('activity', 16)->nullable(); // still|walking|running|in_vehicle|unknown
            $table->unsignedTinyInteger('battery_pct')->nullable();
            $table->string('network', 16)->nullable(); // wifi|cellular|none
            $table->unsignedBigInteger('recorded_at'); // client epoch ms — when the fix was actually taken
            $table->timestamps();

            $table->index(['device_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_locations');
    }
};

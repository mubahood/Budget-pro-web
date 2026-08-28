<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->uuid('uuid')->unique(); // device-generated, stable across reinstalls-with-same-account
            $table->string('name');
            $table->string('platform', 16)->default('android'); // android|ios
            $table->string('model')->nullable();
            $table->string('os_version', 64)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->boolean('tracking_enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_location_at')->nullable();
            $table->unsignedTinyInteger('last_battery_pct')->nullable();
            $table->string('fcm_token')->nullable(); // Phase 2
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_devices');
    }
};

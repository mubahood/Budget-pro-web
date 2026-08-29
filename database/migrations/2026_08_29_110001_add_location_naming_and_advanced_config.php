<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_locations', function (Blueprint $table) {
            $table->string('place_name')->nullable()->after('lng');
        });

        Schema::table('tracked_devices', function (Blueprint $table) {
            $table->string('last_location_name')->nullable()->after('last_lng');
        });

        Schema::table('device_configs', function (Blueprint $table) {
            // 0 = report every fix regardless of movement (current behaviour).
            $table->unsignedInteger('min_distance_meters')->default(0)->after('high_accuracy_mode');
            // Null = always use tracking_interval_seconds, even when still.
            $table->unsignedInteger('stationary_interval_seconds')->nullable()->after('min_distance_meters');
            $table->boolean('geocoding_enabled')->default(true)->after('stationary_interval_seconds');
            $table->unsignedTinyInteger('low_battery_threshold_pct')->default(5)->after('geocoding_enabled');
        });

        // Reverse-geocode results keyed by a rounded coordinate cell (~11m at
        // 4 decimals) — devices revisit the same handful of places constantly,
        // so this turns almost every lookup after the first into a free hit
        // instead of another call against Nominatim's shared, rate-limited API.
        Schema::create('geocode_cache', function (Blueprint $table) {
            $table->id();
            $table->decimal('lat_rounded', 9, 4);
            $table->decimal('lng_rounded', 9, 4);
            $table->string('place_name');
            $table->timestamp('created_at')->nullable();

            $table->unique(['lat_rounded', 'lng_rounded']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geocode_cache');

        Schema::table('device_configs', function (Blueprint $table) {
            $table->dropColumn(['min_distance_meters', 'stationary_interval_seconds', 'geocoding_enabled', 'low_battery_threshold_pct']);
        });

        Schema::table('tracked_devices', function (Blueprint $table) {
            $table->dropColumn('last_location_name');
        });

        Schema::table('device_locations', function (Blueprint $table) {
            $table->dropColumn('place_name');
        });
    }
};

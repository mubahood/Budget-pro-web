<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The capability model from the brief's Section 6, persisted (PLAN.md §5) —
 * a device declares what it can actually do at registration; the server is
 * the enforcement point for any command that requires a capability, so a
 * device can never be sent (or silently execute) something it never said it
 * supports. See App\Models\PingPinDeviceCapability::supports() for the
 * default-allow-when-undeclared policy this table's data feeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingpin_device_capabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('capability', 64);
            $table->boolean('supported')->default(true);
            $table->timestamp('declared_at');
            $table->timestamps();

            $table->unique(['device_id', 'capability']);
            $table->foreign('device_id')->references('id')->on('tracked_devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingpin_device_capabilities');
    }
};

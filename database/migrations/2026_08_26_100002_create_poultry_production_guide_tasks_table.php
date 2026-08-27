<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-wide reference data (BACKEND_API_MASTER_TASKS.md §9) —
     * farm_type_slug is a value-FK to poultry_farm_types.slug, not a
     * foreign-key constraint, matching the client's own uuid-as-slug
     * convention for this table.
     */
    public function up(): void
    {
        Schema::create('poultry_production_guide_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('farm_type_slug', 64)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('days_after_start')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('client_updated_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_production_guide_tasks');
    }
};

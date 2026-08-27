<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-wide reference data (BACKEND_API_MASTER_TASKS.md §9) — every
     * farm sees the same set of types, so unlike the tenant tables in §8
     * there is deliberately no company_id here.
     */
    public function up(): void
    {
        Schema::create('poultry_farm_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('client_updated_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_farm_types');
    }
};

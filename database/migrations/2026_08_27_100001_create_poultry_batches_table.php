<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poultry_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('name');
            $table->string('type', 64)->index(); // matches poultry_farm_types.slug
            $table->string('source')->nullable();
            $table->date('acquired_date');
            $table->unsignedInteger('start_count')->default(0);
            $table->decimal('cost_per_chick', 15, 2)->default(0);
            $table->string('status', 32)->default('active'); // active|closed
            $table->text('notes')->nullable();
            $table->boolean('is_main_farm')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_batches');
    }
};

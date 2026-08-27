<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poultry_vaccination_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('batch_id')->index();
            $table->string('vaccine');
            $table->string('method', 64)->nullable();
            $table->unsignedInteger('age_days')->default(0);
            $table->unsignedInteger('withdrawal_days')->default(0);
            $table->date('due_date');
            $table->boolean('done')->default(false);
            $table->date('done_date')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_vaccination_events');
    }
};

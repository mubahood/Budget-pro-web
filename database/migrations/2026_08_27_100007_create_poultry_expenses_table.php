<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poultry_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('category', 32); // feed|medicine|labor|utilities|other
            $table->unsignedBigInteger('feed_type_id')->nullable()->index();
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->date('date');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_expenses');
    }
};

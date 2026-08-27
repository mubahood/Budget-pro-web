<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poultry_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('category', 32); // eggs|birds|manure|other
            $table->string('product_label')->nullable();
            $table->decimal('qty', 10, 2)->default(0);
            $table->string('unit', 32)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->date('date');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_sales');
    }
};

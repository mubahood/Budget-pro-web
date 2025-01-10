<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sale_records', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->text('item')->nullable();
            $table->text('description')->nullable();
            $table->text('quantity')->nullable();
            $table->text('unit_price')->nullable();
            $table->text('total_price')->nullable();
            $table->text('customer_name')->nullable();
            $table->text('customer_phone')->nullable();
            $table->text('sold_by')->nullable();
            $table->string('status')->nullable()->default('Pending');
            $table->string('day')->nullable();
            $table->text('local_id')->nullable();
            $table->bigInteger('company_id')->nullable(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_records');
    }
};

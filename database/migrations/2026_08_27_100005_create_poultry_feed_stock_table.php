<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poultry_feed_stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('feed_type_id')->index();
            $table->string('direction', 16); // in|out
            $table->string('source', 32)->nullable(); // purchase|consumption|adjustment
            $table->decimal('qty_kg', 10, 2);
            $table->decimal('cost', 15, 2)->default(0);
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->date('date');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_feed_stock');
    }
};

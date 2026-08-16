<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            $table->string('number')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('status')->default('pending'); // pending | paid | failed | refunded
            $table->string('provider')->nullable();
            $table->string('provider_invoice_id')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};

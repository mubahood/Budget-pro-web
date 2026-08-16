<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->string('status')->default('trialing'); // trialing | active | past_due | canceled | expired
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();       // current period end
            $table->timestamp('canceled_at')->nullable();
            $table->string('provider')->nullable();         // stripe | flutterwave | pesapal | manual
            $table->string('provider_subscription_id')->nullable();
            $table->string('provider_customer_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

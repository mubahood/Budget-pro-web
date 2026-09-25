<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (plan Part E 1–3): WhatsApp/SMS receipts with a public link, debt-book
 * reminders to customers, and mobile-money request-to-pay settled to the shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_records', function (Blueprint $t) {
            if (! Schema::hasColumn('sale_records', 'receipt_token')) {
                $t->string('receipt_token', 40)->nullable()->unique();
                $t->timestamp('receipt_sent_at')->nullable();
                $t->date('due_date')->nullable();
            }
        });
        Schema::table('customers', function (Blueprint $t) {
            if (! Schema::hasColumn('customers', 'payment_terms_days')) {
                $t->unsignedSmallInteger('payment_terms_days')->nullable(); // null = the shop's default
                $t->boolean('reminders_enabled')->default(true);
                $t->timestamp('last_reminded_at')->nullable();
            }
        });
        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'debt_reminders_enabled')) {
                $t->boolean('debt_reminders_enabled')->default(false);
                $t->unsignedSmallInteger('credit_terms_days')->default(30);
                $t->string('momo_subaccount_id', 64)->nullable();
                $t->string('momo_payout_phone', 20)->nullable();
                $t->string('momo_payout_network', 20)->nullable();
            }
        });
        if (! Schema::hasTable('momo_requests')) {
            Schema::create('momo_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('sale_record_id')->nullable()->index();
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->string('phone', 20);
                $t->string('network', 20)->nullable();
                $t->decimal('amount', 20, 2);
                $t->string('currency', 3);
                $t->string('tx_ref', 64)->unique();
                $t->string('provider_id', 64)->nullable();
                $t->string('status', 20)->default('pending'); // pending | successful | failed | cancelled
                $t->string('redirect_url', 500)->nullable();
                $t->unsignedBigInteger('payment_id')->nullable();
                $t->string('error', 500)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('momo_requests');
    }
};

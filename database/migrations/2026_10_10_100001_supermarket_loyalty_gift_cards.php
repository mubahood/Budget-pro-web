<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket plan, phase 3 payments (budget-pro-new/docs/SUPERMARKET_PLAN.md C1, C2):
 *  C1 loyalty points: `loyalty_ledger` (the balance is the sum of the rows, like customer balances);
 *  C2 gift cards: `gift_cards` (hashed code, last 4, balance cache) + `gift_card_ledger` (the truth).
 * Store credit reuses the customer account (payments on account, CustomerService::balance).
 * Additive only: nothing here changes a shop that has not switched the features on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loyalty_ledger')) {
            Schema::create('loyalty_ledger', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('customer_id');
                $t->integer('points'); // + earned / given back, − redeemed / taken back
                $t->unsignedBigInteger('sale_record_id')->nullable();
                $t->unsignedBigInteger('payment_id')->nullable(); // the "Points" tender row it paid (redeem)
                $t->string('reason', 10); // earn | redeem | adjust | expire | reverse
                $t->timestamp('expires_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'customer_id'], 'loyalty_ledger_customer_idx');
                $t->index(['sale_record_id'], 'loyalty_ledger_sale_idx');
                $t->index(['payment_id'], 'loyalty_ledger_payment_idx');
            });
        }

        if (! Schema::hasTable('gift_cards')) {
            Schema::create('gift_cards', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->char('code_hash', 64);
                $t->string('last4', 4);
                $t->decimal('balance', 20, 2)->default(0); // cache of SUM(gift_card_ledger.amount)
                $t->timestamp('expires_at')->nullable();
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->boolean('is_active')->default(true);
                $t->string('source', 10)->default('sold'); // sold | refund
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'code_hash'], 'gift_cards_company_code_unique');
                $t->index(['company_id', 'is_active'], 'gift_cards_company_active_idx');
            });
        }

        if (! Schema::hasTable('gift_card_ledger')) {
            Schema::create('gift_card_ledger', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('gift_card_id');
                $t->decimal('amount', 20, 2); // + sold / given back, − spent
                $t->string('reason', 10); // issue | redeem | refund | reverse | adjust
                $t->unsignedBigInteger('sale_record_id')->nullable();
                $t->unsignedBigInteger('payment_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['gift_card_id'], 'gift_card_ledger_card_idx');
                $t->index(['company_id', 'created_at'], 'gift_card_ledger_company_created_idx');
                $t->index(['payment_id'], 'gift_card_ledger_payment_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_ledger');
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('loyalty_ledger');
    }
};

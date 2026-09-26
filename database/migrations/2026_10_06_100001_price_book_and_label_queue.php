<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket phase 2, "the shelf" (budget-pro-new/docs/SUPERMARKET_PLAN.md B1, B6): the price book
 * (every price change, and changes scheduled for later) and the shelf-label queue. New tables only,
 * written only when a shop has `price_book` / `shelf_labels` on (StoreFeatures).
 */
return new class extends Migration
{
    public function up(): void
    {
        // B1: one row per price change, applied (applied_at) or waiting for starts_at.
        if (! Schema::hasTable('price_changes')) {
            Schema::create('price_changes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('stock_item_id');
                $t->unsignedBigInteger('unit_id')->nullable(); // null = the product's own price
                $t->string('field', 10); // selling | buying
                $t->decimal('old', 20, 2)->nullable(); // for a scheduled change: filled in when it is applied
                $t->decimal('new', 20, 2);
                $t->dateTime('starts_at')->nullable(); // UTC
                $t->string('reason', 191)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('applied_at')->nullable();
                $t->dateTime('cancelled_at')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'stock_item_id', 'id'], 'price_changes_item_idx');
                $t->index(['applied_at', 'cancelled_at', 'starts_at'], 'price_changes_due_idx');
            });
        }

        // B6: labels waiting to be printed.
        if (! Schema::hasTable('label_queue')) {
            Schema::create('label_queue', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('stock_item_id');
                $t->unsignedBigInteger('unit_id')->nullable();
                $t->string('reason', 20); // price_change | promotion | new | manual
                $t->timestamp('created_at')->nullable();
                $t->timestamp('printed_at')->nullable();
                $t->index(['company_id', 'printed_at'], 'label_queue_company_printed_idx');
                $t->index(['company_id', 'stock_item_id'], 'label_queue_item_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('label_queue');
        Schema::dropIfExists('price_changes');
    }
};

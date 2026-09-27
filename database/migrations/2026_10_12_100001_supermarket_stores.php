<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket plan, phase 4 "several stores" (docs: budget-pro-new/docs/SUPERMARKET_PLAN.md G1, G2, G3).
 * Additive only; a shop with one location, or with `store_prices` / `store_scoping` off, reads none of it:
 *  - location_prices: a store's own price (null = the usual price) and whether it sells the product at all (G1);
 *  - price_changes.location_id: a scheduled price for one store, applied by prices:apply-due (G1 + B1);
 *  - stock_requests + stock_request_items: a store asks the warehouse for stock (G2);
 *  - stock_transfers.status / sent_at / received_at…: a transfer sent now and received later ("in transit") (G2);
 *  - company_members.location_id: the store a member works at, for store-level access (G3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('location_prices')) {
            Schema::create('location_prices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('location_id');
                $t->unsignedBigInteger('stock_item_id');
                $t->unsignedBigInteger('unit_id')->nullable(); // null = the product's own unit
                $t->decimal('price', 20, 2)->nullable();       // null = the usual price
                $t->boolean('is_available')->default(true);
                $t->timestamps();
                $t->index(['company_id', 'location_id', 'stock_item_id'], 'location_prices_lookup');
                $t->index(['company_id', 'stock_item_id']);
            });
        }
        if (Schema::hasTable('price_changes') && ! Schema::hasColumn('price_changes', 'location_id')) {
            Schema::table('price_changes', fn (Blueprint $t) => $t->unsignedBigInteger('location_id')->nullable());
        }
        if (! Schema::hasTable('stock_requests')) {
            Schema::create('stock_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('number', 40);
                $t->unsignedBigInteger('from_location_id'); // the warehouse that sends
                $t->unsignedBigInteger('to_location_id');   // the store that asks
                $t->string('status', 20)->default('requested'); // requested | approved | sent | received | cancelled
                $t->string('notes', 500)->nullable();
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->unsignedBigInteger('sent_by')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->unsignedBigInteger('received_by')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->unsignedBigInteger('cancelled_by')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->unsignedBigInteger('stock_transfer_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'number']);
                $t->index(['company_id', 'status']);
            });
        }
        if (! Schema::hasTable('stock_request_items')) {
            Schema::create('stock_request_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('stock_request_id')->index();
                $t->unsignedBigInteger('stock_item_id');
                $t->decimal('quantity', 15, 3);                    // asked for
                $t->decimal('approved_quantity', 15, 3)->nullable();
                $t->decimal('sent_quantity', 15, 3)->nullable();
                $t->decimal('received_quantity', 15, 3)->nullable();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $t) {
                if (! Schema::hasColumn('stock_transfers', 'status')) {
                    $t->string('status', 20)->nullable(); // null = moved at once (as before); in_transit | received
                    $t->timestamp('sent_at')->nullable();
                    $t->timestamp('received_at')->nullable();
                    $t->unsignedBigInteger('received_by_id')->nullable();
                    $t->unsignedBigInteger('stock_request_id')->nullable();
                }
            });
        }
        if (Schema::hasTable('stock_transfer_items') && ! Schema::hasColumn('stock_transfer_items', 'received_quantity')) {
            Schema::table('stock_transfer_items', fn (Blueprint $t) => $t->decimal('received_quantity', 15, 3)->nullable());
        }
        if (Schema::hasTable('company_members') && ! Schema::hasColumn('company_members', 'location_id')) {
            Schema::table('company_members', fn (Blueprint $t) => $t->unsignedBigInteger('location_id')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_request_items');
        Schema::dropIfExists('stock_requests');
        Schema::dropIfExists('location_prices');
        foreach ([['price_changes', ['location_id']], ['stock_transfers', ['status', 'sent_at', 'received_at', 'received_by_id', 'stock_request_id']],
            ['stock_transfer_items', ['received_quantity']], ['company_members', ['location_id']]] as [$table, $cols]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $cols[0])) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($cols));
            }
        }
    }
};

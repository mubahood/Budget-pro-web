<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket plan, phase 2 "the shelf" (docs: budget-pro-new/docs/SUPERMARKET_PLAN.md D1, D2, B4, D3).
 * Additive only; every column is nullable or defaults to "nothing", and none is written unless the
 * shop has the matching StoreFeatures feature on:
 *  - goods_receipts.discrepancies: short / over quantities against the purchase order (scan_receiving);
 *  - batch_markdowns: reduced-price labels for short-dated batches (markdowns);
 *  - stock_items.shelf_location, stock_takes.shelf_location, stock_take_items.needs_recount /
 *    first_count: shelf locations and aisle counts with recounts (aisle_counts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $t) {
            if (! Schema::hasColumn('goods_receipts', 'discrepancies')) {
                $t->json('discrepancies')->nullable();
            }
        });
        if (! Schema::hasTable('batch_markdowns')) {
            Schema::create('batch_markdowns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('stock_batch_id')->index();
                $t->unsignedBigInteger('stock_item_id');
                $t->string('barcode', 32);
                $t->decimal('pct', 5, 2);
                $t->decimal('original_price', 20, 2);
                $t->decimal('price', 20, 2);
                $t->decimal('quantity', 15, 3)->default(0); // on the batch when it was marked down
                $t->decimal('sold_qty', 15, 3)->default(0);
                $t->decimal('sold_value', 20, 2)->default(0);
                $t->string('status', 12)->default('active'); // active | ended
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'barcode']);
                $t->index(['company_id', 'status']);
            });
        }
        Schema::table('stock_items', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_items', 'shelf_location')) {
                $t->string('shelf_location', 40)->nullable();
                $t->index(['company_id', 'shelf_location']);
            }
        });
        Schema::table('stock_takes', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_takes', 'shelf_location')) {
                $t->string('shelf_location', 40)->nullable();
            }
        });
        Schema::table('stock_take_items', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_take_items', 'needs_recount')) {
                $t->boolean('needs_recount')->default(false);
                $t->decimal('first_count', 15, 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_markdowns');
        foreach ([['goods_receipts', ['discrepancies']], ['stock_takes', ['shelf_location']], ['stock_take_items', ['needs_recount', 'first_count']]] as [$table, $cols]) {
            foreach ($cols as $col) {
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
        if (Schema::hasColumn('stock_items', 'shelf_location')) {
            Schema::table('stock_items', function (Blueprint $t) {
                $t->dropIndex(['company_id', 'shelf_location']);
                $t->dropColumn('shelf_location');
            });
        }
    }
};

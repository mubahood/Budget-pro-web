<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket plan, phase 4 purchasing (docs: budget-pro-new/docs/SUPERMARKET_PLAN.md D5, D7, D8).
 * Additive only; every column is nullable or defaults to "nothing", and none is written unless the
 * shop has the matching StoreFeatures feature on:
 *  - stock_items.purchase_unit_id, suppliers.min_order_value: order in whole packs, up to the
 *    supplier's minimum order (smart_reorder);
 *  - supplier_prices: what each supplier charges per product, with history (supplier_prices);
 *  - goods_receipts.landed_costs / landed_cost_total / landed_split, goods_receipt_items.landed_unit_cost:
 *    transport, duty and handling spread over a delivery (landed_cost);
 *  - stock_items.consignment_supplier_id, goods_receipts.consignment_value, goods_receipt_items.is_consignment,
 *    purchase_returns.consignment_value, consignment_settlements: sale-or-return stock (consignment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_items', 'purchase_unit_id')) {
                $t->unsignedBigInteger('purchase_unit_id')->nullable();
            }
            if (! Schema::hasColumn('stock_items', 'consignment_supplier_id')) {
                $t->unsignedBigInteger('consignment_supplier_id')->nullable();
                $t->index(['company_id', 'consignment_supplier_id']);
            }
        });
        Schema::table('suppliers', function (Blueprint $t) {
            if (! Schema::hasColumn('suppliers', 'min_order_value')) {
                $t->decimal('min_order_value', 20, 2)->nullable();
            }
        });
        if (! Schema::hasTable('supplier_prices')) {
            Schema::create('supplier_prices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('supplier_id');
                $t->unsignedBigInteger('stock_item_id');
                $t->unsignedBigInteger('unit_id')->nullable(); // null = the product's own unit
                $t->decimal('cost', 20, 2);
                $t->date('valid_from');
                $t->string('source', 20)->default('manual'); // manual | receipt
                $t->unsignedBigInteger('source_id')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'stock_item_id', 'supplier_id']);
                $t->index(['company_id', 'supplier_id', 'valid_from']);
            });
        }
        Schema::table('goods_receipts', function (Blueprint $t) {
            if (! Schema::hasColumn('goods_receipts', 'landed_costs')) {
                $t->json('landed_costs')->nullable();
                $t->decimal('landed_cost_total', 20, 2)->default(0);
                $t->string('landed_split', 10)->nullable(); // value | quantity
            }
            if (! Schema::hasColumn('goods_receipts', 'consignment_value')) {
                $t->decimal('consignment_value', 20, 2)->default(0);
            }
        });
        Schema::table('goods_receipt_items', function (Blueprint $t) {
            if (! Schema::hasColumn('goods_receipt_items', 'landed_unit_cost')) {
                $t->decimal('landed_unit_cost', 20, 4)->nullable();
            }
            if (! Schema::hasColumn('goods_receipt_items', 'is_consignment')) {
                $t->boolean('is_consignment')->default(false);
            }
        });
        Schema::table('purchase_returns', function (Blueprint $t) {
            if (! Schema::hasColumn('purchase_returns', 'consignment_value')) {
                $t->decimal('consignment_value', 20, 2)->default(0);
            }
        });
        if (! Schema::hasTable('consignment_settlements')) {
            Schema::create('consignment_settlements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('supplier_id');
                $t->string('number', 40);
                $t->date('settled_on');
                $t->unsignedBigInteger('through_record_id'); // stock_records.id: sales up to here are settled
                $t->decimal('quantity', 15, 3)->default(0);
                $t->decimal('amount', 20, 2)->default(0);
                $t->json('lines')->nullable(); // [{stock_item_id, name, quantity, unit_cost, amount}]
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'supplier_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_settlements');
        Schema::dropIfExists('supplier_prices');
        foreach ([
            'purchase_returns' => ['consignment_value'],
            'goods_receipt_items' => ['landed_unit_cost', 'is_consignment'],
            'goods_receipts' => ['landed_costs', 'landed_cost_total', 'landed_split', 'consignment_value'],
            'suppliers' => ['min_order_value'],
        ] as $table => $cols) {
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as $c) {
                    if (Schema::hasColumn($table, $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
        Schema::table('stock_items', function (Blueprint $t) {
            if (Schema::hasColumn('stock_items', 'consignment_supplier_id')) {
                $t->dropIndex(['company_id', 'consignment_supplier_id']);
                $t->dropColumn('consignment_supplier_id');
            }
            if (Schema::hasColumn('stock_items', 'purchase_unit_id')) {
                $t->dropColumn('purchase_unit_id');
            }
        });
    }
};

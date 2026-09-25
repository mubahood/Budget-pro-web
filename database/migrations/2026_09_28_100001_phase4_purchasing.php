<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4-1 / P4-3 (plan A5, A7): purchase orders with real line items and a simple
 * lifecycle, receiving against a PO (partials, cost variances), purchase returns,
 * supplier lead times; the EOQ forecasting/auto-reorder tables go (never ran,
 * no rows in production — DECISIONS E39).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('purchase_orders');
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('number', 40);
            $t->unsignedBigInteger('supplier_id')->nullable()->index();
            $t->string('status', 20)->default('draft'); // draft | sent | partially_received | received | cancelled
            $t->date('order_date');
            $t->date('expected_date')->nullable();
            $t->decimal('subtotal', 20, 2)->default(0);
            $t->string('notes', 500)->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'number']);
        });
        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('purchase_order_id')->index();
            $t->unsignedBigInteger('stock_item_id')->index();
            $t->decimal('quantity', 15, 3);
            $t->decimal('received_quantity', 15, 3)->default(0);
            $t->decimal('unit_cost', 20, 2)->default(0);
            $t->timestamps();
        });
        Schema::table('goods_receipts', function (Blueprint $t) {
            if (! Schema::hasColumn('goods_receipts', 'purchase_order_id')) {
                $t->unsignedBigInteger('purchase_order_id')->nullable()->index();
            }
        });
        Schema::table('goods_receipt_items', function (Blueprint $t) {
            if (! Schema::hasColumn('goods_receipt_items', 'purchase_order_item_id')) {
                $t->unsignedBigInteger('purchase_order_item_id')->nullable();
                $t->decimal('expected_unit_cost', 20, 2)->nullable(); // PO cost; variance = (unit_cost - expected) × quantity
            }
        });
        if (! Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('number', 40);
                $t->unsignedBigInteger('supplier_id')->nullable()->index();
                $t->unsignedBigInteger('goods_receipt_id')->nullable();
                $t->date('returned_on');
                $t->decimal('total_value', 20, 2)->default(0);
                $t->decimal('refund_amount', 20, 2)->default(0);
                $t->string('refund_method', 30)->nullable();
                $t->string('reason', 255)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'number']);
            });
            Schema::create('purchase_return_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('purchase_return_id')->index();
                $t->unsignedBigInteger('stock_item_id');
                $t->decimal('quantity', 15, 3);
                $t->decimal('unit_cost', 20, 2);
                $t->unsignedBigInteger('stock_record_id')->nullable();
                $t->timestamps();
            });
        }
        Schema::table('suppliers', function (Blueprint $t) {
            if (! Schema::hasColumn('suppliers', 'lead_time_days')) {
                $t->unsignedSmallInteger('lead_time_days')->default(7);
            }
        });

        Schema::dropIfExists('inventory_forecasts');
        Schema::dropIfExists('auto_reorder_rules');
        $dead = DB::table('admin_menu')->whereIn('uri', ['inventory-forecasts', 'auto-reorder-rules', 'inventory-forecasts-generate'])->pluck('id');
        DB::table('admin_role_menu')->whereIn('menu_id', $dead)->delete();
        DB::table('admin_menu')->whereIn('id', $dead)->delete();

        // Purchase orders and returns in the Shop menu.
        $shop = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Shop')->value('id');
        if ($shop) {
            $order = (int) DB::table('admin_menu')->where('parent_id', $shop)->max('order');
            foreach ([['Purchase orders', 'purchase-orders', 'fa-file-text-o'], ['Returns to supplier', 'purchase-returns', 'fa-reply'], ['Reorder list', 'reorder-suggestions', 'fa-refresh']] as [$title, $uri, $icon]) {
                if (DB::table('admin_menu')->where('uri', $uri)->exists()) {
                    DB::table('admin_menu')->where('uri', $uri)->update(['title' => $title, 'parent_id' => $shop]);
                } else {
                    DB::table('admin_menu')->insert(['parent_id' => $shop, 'order' => ++$order, 'title' => $title, 'icon' => $icon, 'uri' => $uri, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        if (DB::table('admin_roles')->exists()) {
            \App\Support\AdminAccess::scopeTenantRoles();
            \App\Support\AdminAccess::ensureShopRoles();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_order_items');
    }
};

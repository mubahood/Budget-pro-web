<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket plan, phase 3 "pricing" (docs: budget-pro-new/docs/SUPERMARKET_PLAN.md B2, B3, H5).
 * Additive only; nothing here is read or written unless the shop has `price_levels` / `promotions` on:
 *  - product_prices: level prices and quantity breaks per product (and unit);
 *  - customers.price_level: the level a customer buys at (retail when empty);
 *  - promotions + promotion_targets: the promotions engine's rules;
 *  - sale_record_items.promo_discount: the part of a line's discount given by promotions;
 *  - sale_promotions: which promotions a sale got, by name, and how much each gave.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_prices')) {
            Schema::create('product_prices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('stock_item_id');
                $t->unsignedBigInteger('unit_id')->nullable(); // null = the product's own unit
                $t->string('level', 40)->default('retail');     // retail | wholesale | member | a shop's own name
                $t->decimal('price', 20, 2);
                $t->decimal('min_qty', 15, 3)->default(1);
                $t->timestamps();
                $t->index(['company_id', 'stock_item_id']);
            });
        }
        Schema::table('customers', function (Blueprint $t) {
            if (! Schema::hasColumn('customers', 'price_level')) {
                $t->string('price_level', 40)->nullable();
            }
        });
        if (! Schema::hasTable('promotions')) {
            Schema::create('promotions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('name', 120);
                $t->string('type', 20);               // percent_off | amount_off | fixed_price | buy_x_get_y | mix_match | bundle | spend_save | coupon
                $t->json('rules')->nullable();
                $t->timestamp('starts_at')->nullable(); // UTC
                $t->timestamp('ends_at')->nullable();
                $t->json('window')->nullable();         // {days: [1..7], from: "HH:MM", to: "HH:MM"} in the shop's time
                $t->boolean('member_only')->default(false);
                $t->boolean('stackable')->default(false);
                $t->integer('priority')->default(0);
                $t->unsignedInteger('per_sale_limit')->nullable();
                $t->boolean('is_active')->default(true);
                $t->string('code', 40)->nullable();     // a coupon code typed at the till
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'is_active']);
            });
        }
        if (! Schema::hasTable('promotion_targets')) {
            Schema::create('promotion_targets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('promotion_id')->index();
                $t->string('target_type', 20); // product | category | sub_category
                $t->unsignedBigInteger('target_id');
            });
        }
        Schema::table('sale_record_items', function (Blueprint $t) {
            if (! Schema::hasColumn('sale_record_items', 'promo_discount')) {
                $t->decimal('promo_discount', 20, 2)->nullable();
            }
        });
        if (! Schema::hasTable('sale_promotions')) {
            Schema::create('sale_promotions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('sale_id')->index();
                $t->unsignedBigInteger('promotion_id')->nullable();
                $t->string('name', 160);
                $t->decimal('amount', 20, 2);
                $t->timestamps();
                $t->index(['company_id', 'promotion_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_promotions');
        Schema::dropIfExists('promotion_targets');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('product_prices');
        foreach ([['customers', 'price_level'], ['sale_record_items', 'promo_discount']] as [$table, $col]) {
            if (Schema::hasColumn($table, $col)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};

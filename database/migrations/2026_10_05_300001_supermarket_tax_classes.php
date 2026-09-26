<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket plan F1 (budget-pro-new/docs/SUPERMARKET_PLAN.md): tax classes per product.
 *  - `tax_classes`: a shop's classes (standard, reduced, zero-rated, exempt…), one of them the default;
 *  - stock_items.tax_class_id: the product's class (null = the shop's default);
 *  - sale_record_items.tax_class_id / tax_rate / tax_amount: the tax worked out on the line at sale time.
 * Additive only: all null unless the shop has switched on `tax_classes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_classes')) {
            Schema::create('tax_classes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('name', 100);
                $t->string('code', 20)->default('standard'); // standard | reduced | zero | exempt
                $t->decimal('rate', 7, 3)->default(0);
                $t->boolean('is_default')->default(false);
                $t->timestamps();
                $t->index(['company_id', 'is_default'], 'tax_classes_company_default_idx');
            });
        }
        if (! Schema::hasColumn('stock_items', 'tax_class_id')) {
            Schema::table('stock_items', fn (Blueprint $t) => $t->unsignedBigInteger('tax_class_id')->nullable());
        }
        Schema::table('sale_record_items', function (Blueprint $t) {
            if (! Schema::hasColumn('sale_record_items', 'tax_class_id')) {
                $t->unsignedBigInteger('tax_class_id')->nullable();
            }
            if (! Schema::hasColumn('sale_record_items', 'tax_rate')) {
                $t->decimal('tax_rate', 7, 3)->nullable();
            }
            if (! Schema::hasColumn('sale_record_items', 'tax_amount')) {
                $t->decimal('tax_amount', 20, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['tax_amount', 'tax_rate', 'tax_class_id'] as $col) {
            if (Schema::hasColumn('sale_record_items', $col)) {
                Schema::table('sale_record_items', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        if (Schema::hasColumn('stock_items', 'tax_class_id')) {
            Schema::table('stock_items', fn (Blueprint $t) => $t->dropColumn('tax_class_id'));
        }
        Schema::dropIfExists('tax_classes');
    }
};

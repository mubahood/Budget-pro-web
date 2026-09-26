<?php

use App\Services\Shop\BarcodeService;
use App\Support\StoreFeatures;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket phase 1, "the lane" (budget-pro-new/docs/SUPERMARKET_PLAN.md A1, A2, A4, A6, A7, A10, A11).
 * Additive only: every new column is nullable or defaults to today's behaviour, so a shop that never
 * turns a feature on sees nothing change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A1: the product's own barcode, kept as a "primary" row beside its pack barcodes.
        if (Schema::hasTable('product_barcodes') && ! Schema::hasColumn('product_barcodes', 'is_primary')) {
            Schema::table('product_barcodes', fn (Blueprint $t) => $t->boolean('is_primary')->default(false)->after('unit_id'));
        }

        Schema::table('stock_items', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_items', 'sold_by')) {
                $t->string('sold_by', 10)->default('unit'); // A2: unit | weight | length | volume
            }
            if (! Schema::hasColumn('stock_items', 'plu_code')) {
                $t->string('plu_code', 20)->nullable(); // A2: typed at the till, and the item code on scale labels
            }
            if (! Schema::hasColumn('stock_items', 'open_price')) {
                $t->boolean('open_price')->default(false); // A4: a department key that asks for the price
            }
            if (! Schema::hasColumn('stock_items', 'min_age')) {
                $t->unsignedTinyInteger('min_age')->nullable(); // A10
            }
            if (! Schema::hasColumn('stock_items', 'deposit_item_id')) {
                $t->unsignedBigInteger('deposit_item_id')->nullable(); // A11: the crate/bottle deposit sold with it
            }
        });
        if (! $this->hasIndex('stock_items', 'stock_items_company_plu_idx')) {
            Schema::table('stock_items', fn (Blueprint $t) => $t->index(['company_id', 'plu_code'], 'stock_items_company_plu_idx'));
        }

        Schema::table('sale_records', function (Blueprint $t) {
            if (! Schema::hasColumn('sale_records', 'rounding_amount')) {
                $t->decimal('rounding_amount', 20, 2)->nullable(); // A7: cash rounding, part of total_amount
            }
            if (! Schema::hasColumn('sale_records', 'age_checked_by')) {
                $t->unsignedBigInteger('age_checked_by')->nullable(); // A10: the cashier who confirmed the age
            }
        });

        // A6: carts held on the server, resumable from any lane.
        if (! Schema::hasTable('held_carts')) {
            Schema::create('held_carts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('location_id')->nullable();
                $t->string('label', 191);
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->json('lines');
                $t->unsignedInteger('line_count')->default(0);
                $t->decimal('total', 20, 2)->default(0);
                $t->timestamps();
                $t->index(['company_id', 'created_at'], 'held_carts_company_created_idx');
            });
        }

        // A1: the existing barcodes of shops that already use pack barcodes (idempotent; others are
        // backfilled when they turn the feature on, StoreFeatures::update).
        $companies = DB::table('companies')->where(fn ($q) => $q->whereNotNull('store_settings')->orWhere('business_type', 'supermarket'))->pluck('id');
        foreach ($companies as $id) {
            $company = \App\Models\Company::withoutGlobalScopes()->find($id);
            if ($company && StoreFeatures::enabled($company, 'pack_barcodes')) {
                BarcodeService::backfillPrimary((int) $id);
            }
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)->where('index_name', $name)->exists();
    }

    public function down(): void
    {
        Schema::dropIfExists('held_carts');
        if ($this->hasIndex('stock_items', 'stock_items_company_plu_idx')) {
            Schema::table('stock_items', fn (Blueprint $t) => $t->dropIndex('stock_items_company_plu_idx'));
        }
        foreach (['sold_by', 'plu_code', 'open_price', 'min_age', 'deposit_item_id'] as $col) {
            if (Schema::hasColumn('stock_items', $col)) {
                Schema::table('stock_items', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        foreach (['rounding_amount', 'age_checked_by'] as $col) {
            if (Schema::hasColumn('sale_records', $col)) {
                Schema::table('sale_records', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        if (Schema::hasColumn('product_barcodes', 'is_primary')) {
            Schema::table('product_barcodes', fn (Blueprint $t) => $t->dropColumn('is_primary'));
        }
    }
};

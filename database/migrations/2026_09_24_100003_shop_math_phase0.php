<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 shop-math schema (SHOP_ONBOARDING_OFFLINE_MASTER_PLAN.md §A2, P0-4..P0-11).
 * Additive and guarded; safe to run on production and on the test schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- stock_items: real decimals, per-product low-stock + negative-stock policy ----
        DB::statement('ALTER TABLE stock_items MODIFY buying_price DECIMAL(20,2) NOT NULL DEFAULT 0, MODIFY selling_price DECIMAL(20,2) NOT NULL DEFAULT 0, MODIFY original_quantity DECIMAL(15,3) NOT NULL DEFAULT 0, MODIFY current_quantity DECIMAL(15,3) NOT NULL DEFAULT 0');
        Schema::table('stock_items', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_items', 'min_stock')) {
                $table->decimal('min_stock', 15, 3)->nullable()->after('current_quantity');
            }
            if (! Schema::hasColumn('stock_items', 'allow_negative_stock')) {
                $table->boolean('allow_negative_stock')->default(false)->after('min_stock');
            }
        });

        // ---- stock_records: signed delta, idempotency, reference + reversal links ----
        DB::statement('ALTER TABLE stock_records MODIFY quantity DECIMAL(15,3) NOT NULL DEFAULT 0');
        Schema::table('stock_records', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_records', 'quantity_delta')) {
                $table->decimal('quantity_delta', 15, 3)->default(0)->after('quantity');
            }
            if (! Schema::hasColumn('stock_records', 'client_uuid')) {
                $table->char('client_uuid', 36)->nullable()->after('id');
                $table->unique(['company_id', 'client_uuid'], 'stock_records_company_client_uuid_unique');
            }
            if (! Schema::hasColumn('stock_records', 'reference_type')) {
                $table->string('reference_type', 40)->nullable()->after('profit');
                $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
                $table->index(['company_id', 'reference_type', 'reference_id'], 'stock_records_reference_idx');
            }
            if (! Schema::hasColumn('stock_records', 'is_reversal')) {
                $table->boolean('is_reversal')->default(false)->after('reference_id');
                $table->unsignedBigInteger('reverses_id')->nullable()->after('is_reversal');
            }
            if (! Schema::hasColumn('stock_records', 'unit_cost')) {
                $table->decimal('unit_cost', 20, 2)->nullable()->after('buying_price');
            }
        });
        // Backfill the signed delta for historical rows: only Sale ever moved stock.
        DB::statement("UPDATE stock_records SET quantity_delta = CASE WHEN type = 'Sale' THEN -ABS(quantity) ELSE 0 END WHERE quantity_delta = 0");

        // ---- sale_records: idempotency, discounts, change, currency, void, per-company numbering ----
        Schema::table('sale_records', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_records', 'client_uuid')) {
                $table->char('client_uuid', 36)->nullable()->after('id');
                $table->unique(['company_id', 'client_uuid'], 'sale_records_company_client_uuid_unique');
            }
            if (! Schema::hasColumn('sale_records', 'subtotal')) {
                $table->decimal('subtotal', 20, 2)->default(0)->after('customer_address');
                $table->decimal('discount_amount', 20, 2)->default(0)->after('subtotal');
                $table->string('discount_reason', 191)->nullable()->after('discount_amount');
                $table->decimal('change_given', 20, 2)->default(0)->after('balance');
                $table->string('currency', 3)->nullable()->after('change_given');
            }
            if (! Schema::hasColumn('sale_records', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('notes');
                $table->timestamp('voided_at')->nullable()->after('processed_at');
                $table->unsignedBigInteger('voided_by_id')->nullable()->after('voided_at');
                $table->string('voided_reason', 500)->nullable()->after('voided_by_id');
            }
        });
        DB::statement('ALTER TABLE sale_records MODIFY total_amount DECIMAL(20,2) NOT NULL DEFAULT 0, MODIFY amount_paid DECIMAL(20,2) NOT NULL DEFAULT 0, MODIFY balance DECIMAL(20,2) NOT NULL DEFAULT 0');
        // Numbers are assigned when the sale is finalised (inside the checkout transaction), so a draft header may not have one yet.
        DB::statement('ALTER TABLE sale_records MODIFY receipt_number VARCHAR(191) NULL, MODIFY invoice_number VARCHAR(191) NULL');
        $this->dropIndexIfExists('sale_records', 'sale_records_receipt_number_unique');
        $this->dropIndexIfExists('sale_records', 'sale_records_invoice_number_unique');
        $this->addUniqueIfMissing('sale_records', ['company_id', 'receipt_number'], 'sale_records_company_receipt_unique');
        $this->addUniqueIfMissing('sale_records', ['company_id', 'invoice_number'], 'sale_records_company_invoice_unique');
        DB::statement('UPDATE sale_records SET subtotal = total_amount WHERE subtotal = 0 AND total_amount > 0');
        DB::statement('UPDATE sale_records SET processed_at = created_at WHERE processed_at IS NULL AND total_amount > 0');

        // ---- sale_record_items: tenant column, line discount/net total ----
        Schema::table('sale_record_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_record_items', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->after('id');
                $table->index('company_id', 'sale_record_items_company_id_index');
            }
            if (! Schema::hasColumn('sale_record_items', 'discount_amount')) {
                $table->decimal('discount_amount', 20, 2)->default(0)->after('subtotal');
                $table->decimal('line_total', 20, 2)->default(0)->after('discount_amount');
            }
        });
        DB::statement('UPDATE sale_record_items sri JOIN sale_records sr ON sri.sale_record_id = sr.id SET sri.company_id = sr.company_id WHERE sri.company_id IS NULL');
        DB::statement('UPDATE sale_record_items SET line_total = subtotal WHERE line_total = 0');
        DB::statement('ALTER TABLE sale_record_items MODIFY quantity DECIMAL(15,3) NOT NULL DEFAULT 0');

        // ---- payments ----
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->char('client_uuid', 36)->nullable();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('sale_record_id')->nullable();
                $table->string('method', 30)->default('cash');
                $table->string('provider', 50)->nullable();
                $table->string('reference', 191)->nullable();
                $table->decimal('amount', 20, 2);
                $table->string('currency', 3)->nullable();
                $table->timestamp('received_at')->nullable();
                $table->unsignedBigInteger('received_by_id')->nullable();
                $table->unsignedBigInteger('financial_record_id')->nullable();
                $table->boolean('is_reversal')->default(false);
                $table->unsignedBigInteger('reverses_id')->nullable();
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'client_uuid'], 'payments_company_client_uuid_unique');
                $table->index(['company_id', 'sale_record_id'], 'payments_company_sale_idx');
                $table->index(['company_id', 'received_at'], 'payments_company_received_idx');
            });
        }

        // ---- financial_records: real money type, source links, reversals ----
        DB::statement('ALTER TABLE financial_records MODIFY amount DECIMAL(20,2) NULL');
        Schema::table('financial_records', function (Blueprint $table) {
            if (! Schema::hasColumn('financial_records', 'source_type')) {
                $table->string('source_type', 40)->nullable()->after('created_by_id');
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
                $table->boolean('is_reversal')->default(false)->after('source_id');
                $table->unsignedBigInteger('reverses_id')->nullable()->after('is_reversal');
                $table->string('currency', 3)->nullable()->after('reverses_id');
                $table->index(['company_id', 'source_type', 'source_id'], 'financial_records_source_idx');
            }
        });

        // ---- financial_categories: type/status, unique per company (after de-duplication) ----
        Schema::table('financial_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('financial_categories', 'type')) {
                $table->string('type', 20)->nullable()->after('name');
                $table->string('status', 20)->default('Active')->after('type');
            }
        });
        DB::statement("UPDATE financial_categories SET type = CASE WHEN LOWER(name) IN ('sales','income','other income') THEN 'Income' ELSE 'Expense' END WHERE type IS NULL");
        $this->deduplicateFinancialCategories();
        $this->addUniqueIfMissing('financial_categories', ['company_id', 'name'], 'financial_categories_company_name_unique');

        // ---- financial_periods: one active period per company, enforced by the database ----
        $this->demoteExtraActivePeriods();
        if (! Schema::hasColumn('financial_periods', 'active_flag')) {
            DB::statement("ALTER TABLE financial_periods ADD COLUMN active_flag TINYINT AS (IF(status = 'Active', 1, NULL)) STORED");
        }
        $this->addUniqueIfMissing('financial_periods', ['company_id', 'active_flag'], 'financial_periods_one_active_unique');
        Schema::table('financial_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('financial_periods', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('description');
                $table->unsignedBigInteger('closed_by_id')->nullable()->after('closed_at');
            }
        });

        // ---- per-company number sequences (Appendix D, server side) ----
        if (! Schema::hasTable('number_sequences')) {
            Schema::create('number_sequences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('kind', 20);
                $table->string('period_key', 10)->default('');
                $table->unsignedBigInteger('last_value')->default(0);
                $table->timestamps();
                $table->unique(['company_id', 'kind', 'period_key'], 'number_sequences_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('payments');
        // Column additions are left in place (additive migration; see B.3 in the plan).
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        $exists = DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $index]);
        if ($exists && (int) $exists->c > 0) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }

    private function addUniqueIfMissing(string $table, array $columns, string $index): void
    {
        $exists = DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $index]);
        if (! $exists || (int) $exists->c === 0) {
            $cols = implode('`,`', $columns);
            DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$index}` (`{$cols}`)");
        }
    }

    /** Merge same-name categories within a company onto the lowest id before the unique index lands. */
    private function deduplicateFinancialCategories(): void
    {
        $dupes = DB::select('SELECT company_id, name, MIN(id) AS keep_id, COUNT(*) AS c FROM financial_categories GROUP BY company_id, name HAVING c > 1');
        foreach ($dupes as $d) {
            $ids = DB::table('financial_categories')->where('company_id', $d->company_id)->where('name', $d->name)->where('id', '!=', $d->keep_id)->pluck('id');
            DB::table('financial_records')->whereIn('financial_category_id', $ids)->update(['financial_category_id' => $d->keep_id]);
            DB::table('financial_categories')->whereIn('id', $ids)->delete();
        }
    }

    /** Keep only the most recently created Active period per company. */
    private function demoteExtraActivePeriods(): void
    {
        $companies = DB::select("SELECT company_id, MAX(id) AS keep_id FROM financial_periods WHERE status = 'Active' GROUP BY company_id HAVING COUNT(*) > 1");
        foreach ($companies as $c) {
            DB::table('financial_periods')->where('company_id', $c->company_id)->where('status', 'Active')->where('id', '!=', $c->keep_id)->update(['status' => 'Inactive']);
        }
    }
};

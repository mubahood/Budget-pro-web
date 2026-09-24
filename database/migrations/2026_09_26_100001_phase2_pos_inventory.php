<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — real POS & inventory (plan A1/A3/A4, P2-1..P2-8). Additive and guarded.
 * New tables carry the standard sync columns from the start.
 */
return new class extends Migration
{
    private function syncColumns(Blueprint $t): void
    {
        $t->char('uuid', 36)->nullable();
        $t->unsignedBigInteger('company_id');
        $t->unsignedBigInteger('server_seq')->default(0);
        $t->unsignedBigInteger('client_created_at')->nullable();
        $t->unsignedBigInteger('client_updated_at')->nullable();
        $t->unsignedInteger('version')->default(1);
        $t->boolean('is_deleted')->default(false);
        $t->char('created_by_uuid', 36)->nullable();
        $t->char('device_id', 36)->nullable();
        $t->unsignedBigInteger('created_by_id')->nullable();
        $t->timestamps();
    }

    private function syncIndexes(Blueprint $t, string $table): void
    {
        $t->unique(['company_id', 'uuid'], "{$table}_company_uuid_unique");
        $t->index(['company_id', 'server_seq'], "{$table}_company_seq_idx");
    }

    public function up(): void
    {
        if (! Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $t) {
                $t->id();
                $this->syncColumns($t);
                $t->string('name', 60);
                $t->string('abbreviation', 15);
                $t->unsignedBigInteger('base_unit_id')->nullable();
                $t->decimal('factor', 15, 3)->default(1); // how many base units in one of this unit
                $this->syncIndexes($t, 'units');
            });
        }

        if (! Schema::hasTable('product_barcodes')) {
            Schema::create('product_barcodes', function (Blueprint $t) {
                $t->id();
                $this->syncColumns($t);
                $t->unsignedBigInteger('stock_item_id');
                $t->string('barcode', 64);
                $t->unsignedBigInteger('unit_id')->nullable(); // scanning a carton code sells a carton
                $this->syncIndexes($t, 'product_barcodes');
                $t->unique(['company_id', 'barcode'], 'product_barcodes_company_barcode_unique');
                $t->index('stock_item_id');
            });
        }

        Schema::table('stock_items', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_items', 'unit_id')) {
                $t->unsignedBigInteger('unit_id')->nullable()->after('stock_sub_category_id');
            }
            if (! Schema::hasColumn('stock_items', 'track_stock')) {
                $t->boolean('track_stock')->default(true)->after('allow_negative_stock');
            }
            if (! Schema::hasColumn('stock_items', 'is_active')) {
                $t->boolean('is_active')->default(true)->after('track_stock');
            }
        });

        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $t) {
                $t->id();
                $this->syncColumns($t);
                $t->string('name', 150);
                $t->string('phone', 30)->nullable();
                $t->string('email', 150)->nullable();
                $t->string('address', 255)->nullable();
                $t->decimal('credit_limit', 20, 2)->nullable();
                $t->decimal('balance', 20, 2)->default(0); // derived: credit sales - payments - returns
                $t->string('notes', 500)->nullable();
                $t->boolean('is_active')->default(true);
                $this->syncIndexes($t, 'customers');
                $t->index(['company_id', 'phone'], 'customers_company_phone_idx');
            });
        }

        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $t) {
                $t->id();
                $this->syncColumns($t);
                $t->string('name', 150);
                $t->string('phone', 30)->nullable();
                $t->string('email', 150)->nullable();
                $t->string('address', 255)->nullable();
                $t->unsignedInteger('payment_terms_days')->default(0);
                $t->decimal('balance', 20, 2)->default(0); // derived: unpaid goods receipts - supplier payments
                $t->string('notes', 500)->nullable();
                $t->boolean('is_active')->default(true);
                $this->syncIndexes($t, 'suppliers');
            });
        }

        if (! Schema::hasTable('shifts')) {
            Schema::create('shifts', function (Blueprint $t) {
                $t->id();
                $this->syncColumns($t);
                $t->string('number', 40)->nullable();
                $t->unsignedBigInteger('opened_by_id')->nullable();
                $t->timestamp('opened_at')->nullable();
                $t->decimal('opening_float', 20, 2)->default(0);
                $t->unsignedBigInteger('closed_by_id')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->decimal('expected_cash', 20, 2)->default(0);
                $t->decimal('counted_cash', 20, 2)->nullable();
                $t->decimal('variance', 20, 2)->nullable();
                $t->decimal('sales_total', 20, 2)->default(0);
                $t->unsignedInteger('sales_count')->default(0);
                $t->string('status', 10)->default('open'); // open | closed
                $t->string('notes', 500)->nullable();
                $this->syncIndexes($t, 'shifts');
                $t->index(['company_id', 'status'], 'shifts_company_status_idx');
            });
        }

        Schema::table('sale_records', function (Blueprint $t) {
            if (! Schema::hasColumn('sale_records', 'customer_id')) {
                $t->unsignedBigInteger('customer_id')->nullable()->after('customer_address');
            }
            if (! Schema::hasColumn('sale_records', 'shift_id')) {
                $t->unsignedBigInteger('shift_id')->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('sale_records', 'refunded_amount')) {
                $t->decimal('refunded_amount', 20, 2)->default(0)->after('change_given');
            }
        });
        Schema::table('sale_record_items', function (Blueprint $t) {
            if (! Schema::hasColumn('sale_record_items', 'returned_quantity')) {
                $t->decimal('returned_quantity', 15, 3)->default(0)->after('quantity');
            }
            if (! Schema::hasColumn('sale_record_items', 'unit_id')) {
                $t->unsignedBigInteger('unit_id')->nullable()->after('stock_item_id');
                $t->decimal('unit_factor', 15, 3)->default(1)->after('unit_id');
            }
        });
        Schema::table('payments', function (Blueprint $t) {
            if (! Schema::hasColumn('payments', 'customer_id')) {
                $t->unsignedBigInteger('customer_id')->nullable()->after('sale_record_id');
                $t->unsignedBigInteger('shift_id')->nullable()->after('customer_id');
                $t->index(['company_id', 'customer_id'], 'payments_company_customer_idx');
            }
        });
        Schema::table('stock_records', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_records', 'reason')) {
                $t->string('reason', 60)->nullable()->after('description');
                $t->string('image', 255)->nullable()->after('reason');
            }
        });

        if (! Schema::hasTable('sale_returns')) {
            Schema::create('sale_returns', function (Blueprint $t) {
                $t->id();
                $this->syncColumns($t);
                $t->unsignedBigInteger('sale_record_id');
                $t->string('reason', 255)->nullable();
                $t->decimal('value', 20, 2)->default(0);        // value of returned goods
                $t->decimal('refund_amount', 20, 2)->default(0); // cash actually handed back
                $t->string('refund_method', 30)->nullable();
                $t->unsignedBigInteger('shift_id')->nullable();
                $this->syncIndexes($t, 'sale_returns');
                $t->index('sale_record_id');
            });
        }
        if (! Schema::hasTable('sale_return_items')) {
            Schema::create('sale_return_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('sale_return_id');
                $t->unsignedBigInteger('sale_record_item_id');
                $t->unsignedBigInteger('stock_item_id');
                $t->decimal('quantity', 15, 3);
                $t->decimal('value', 20, 2);
                $t->boolean('restock')->default(true);
                $t->unsignedBigInteger('stock_record_id')->nullable();
                $t->timestamps();
                $t->index('sale_return_id');
            });
        }

        if (! Schema::hasTable('goods_receipts')) {
            Schema::create('goods_receipts', function (Blueprint $t) {
                $t->id();
                $this->syncColumns($t);
                $t->string('number', 40)->nullable();
                $t->unsignedBigInteger('supplier_id')->nullable();
                $t->string('invoice_ref', 80)->nullable();
                $t->date('received_on')->nullable();
                $t->decimal('total_cost', 20, 2)->default(0);
                $t->decimal('amount_paid', 20, 2)->default(0);
                $t->string('payment_method', 30)->nullable();
                $t->string('notes', 500)->nullable();
                $this->syncIndexes($t, 'goods_receipts');
            });
        }
        if (! Schema::hasTable('goods_receipt_items')) {
            Schema::create('goods_receipt_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('goods_receipt_id');
                $t->unsignedBigInteger('stock_item_id');
                $t->decimal('quantity', 15, 3);
                $t->decimal('unit_cost', 20, 2);
                $t->unsignedBigInteger('stock_record_id')->nullable();
                $t->timestamps();
                $t->index('goods_receipt_id');
            });
        }

        if (! Schema::hasTable('stock_takes')) {
            Schema::create('stock_takes', function (Blueprint $t) {
                $t->id();
                $this->syncColumns($t);
                $t->string('number', 40)->nullable();
                $t->string('name', 120);
                $t->unsignedBigInteger('stock_category_id')->nullable();
                $t->string('status', 12)->default('draft'); // draft | posted | cancelled
                $t->timestamp('posted_at')->nullable();
                $t->unsignedBigInteger('posted_by_id')->nullable();
                $t->string('notes', 500)->nullable();
                $this->syncIndexes($t, 'stock_takes');
            });
        }
        if (! Schema::hasTable('stock_take_items')) {
            Schema::create('stock_take_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('stock_take_id');
                $t->unsignedBigInteger('stock_item_id');
                $t->decimal('system_quantity', 15, 3)->nullable();
                $t->decimal('counted_quantity', 15, 3);
                $t->decimal('delta', 15, 3)->nullable();
                $t->unsignedBigInteger('stock_record_id')->nullable();
                $t->timestamps();
                $t->unique(['stock_take_id', 'stock_item_id'], 'stock_take_items_take_product_unique');
            });
        }

        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'receipt_header')) {
                $t->string('receipt_header', 500)->nullable()->after('is_demo');
                $t->string('receipt_footer', 500)->nullable()->after('receipt_header');
                $t->decimal('low_stock_default', 15, 3)->nullable()->after('receipt_footer');
                $t->boolean('require_shift')->default(false)->after('low_stock_default');
            }
        });

        // Every company gets a default "pcs" unit so products always have one.
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (! DB::table('units')->where('company_id', $companyId)->exists()) {
                $seq = (int) DB::table('sync_sequence')->where('id', 1)->value('last_seq') + 1;
                DB::table('sync_sequence')->where('id', 1)->update(['last_seq' => $seq]);
                DB::table('units')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $companyId, 'server_seq' => $seq, 'name' => 'Piece', 'abbreviation' => 'pcs', 'factor' => 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        foreach (['stock_take_items', 'stock_takes', 'goods_receipt_items', 'goods_receipts', 'sale_return_items', 'sale_returns', 'shifts', 'suppliers', 'customers', 'product_barcodes', 'units'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

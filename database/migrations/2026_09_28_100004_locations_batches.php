<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4-4 (decision H6): locations with stock per location and transfers; batches
 * with expiry and FEFO picking for products that track them. Every company gets
 * a default "Main shop" holding today's stock, so single-shop tenants see no change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('name', 120);
                $t->string('address', 255)->nullable();
                $t->boolean('is_default')->default(false);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['company_id', 'name']);
            });
        }
        if (! Schema::hasTable('stock_levels')) {
            Schema::create('stock_levels', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('location_id');
                $t->unsignedBigInteger('stock_item_id');
                $t->decimal('quantity', 15, 3)->default(0);
                $t->timestamps();
                $t->unique(['location_id', 'stock_item_id']);
                $t->index('stock_item_id');
            });
        }
        if (! Schema::hasTable('stock_transfers')) {
            Schema::create('stock_transfers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('number', 40);
                $t->unsignedBigInteger('from_location_id');
                $t->unsignedBigInteger('to_location_id');
                $t->string('notes', 500)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'number']);
            });
            Schema::create('stock_transfer_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('stock_transfer_id')->index();
                $t->unsignedBigInteger('stock_item_id');
                $t->decimal('quantity', 15, 3);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('stock_batches')) {
            Schema::create('stock_batches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('stock_item_id');
                $t->unsignedBigInteger('location_id')->nullable();
                $t->string('batch_number', 60);
                $t->date('expiry_date')->nullable();
                $t->decimal('quantity', 15, 3)->default(0);
                $t->decimal('unit_cost', 20, 2)->nullable();
                $t->timestamps();
                $t->index(['stock_item_id', 'expiry_date']);
                $t->unique(['stock_item_id', 'location_id', 'batch_number'], 'batch_unique');
            });
            Schema::create('stock_record_batches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('stock_record_id')->index();
                $t->unsignedBigInteger('stock_batch_id')->index();
                $t->decimal('quantity', 15, 3); // signed: + into the batch, − out of it
                $t->timestamps();
            });
        }
        Schema::table('stock_records', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_records', 'location_id')) {
                $t->unsignedBigInteger('location_id')->nullable()->index();
            }
        });
        Schema::table('stock_items', function (Blueprint $t) {
            if (! Schema::hasColumn('stock_items', 'track_batches')) {
                $t->boolean('track_batches')->default(false);
            }
        });
        Schema::table('devices', function (Blueprint $t) {
            if (! Schema::hasColumn('devices', 'location_id')) {
                $t->unsignedBigInteger('location_id')->nullable();
            }
        });
        Schema::table('goods_receipt_items', function (Blueprint $t) {
            if (! Schema::hasColumn('goods_receipt_items', 'batch_number')) {
                $t->string('batch_number', 60)->nullable();
                $t->date('expiry_date')->nullable();
            }
        });

        // Backfill: one default location per company holding today's stock.
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $loc = DB::table('locations')->where('company_id', $companyId)->where('is_default', true)->value('id')
                ?? DB::table('locations')->insertGetId(['company_id' => $companyId, 'name' => 'Main shop', 'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('stock_records')->where('company_id', $companyId)->whereNull('location_id')->update(['location_id' => $loc]);
            DB::statement('INSERT INTO stock_levels (company_id, location_id, stock_item_id, quantity, created_at, updated_at)
                SELECT company_id, ?, id, current_quantity, ?, ? FROM stock_items WHERE company_id = ?
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)', [$loc, $now, $now, $companyId]);
        }

        $shop = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Shop')->value('id');
        if ($shop) {
            $order = (int) DB::table('admin_menu')->where('parent_id', $shop)->max('order');
            foreach ([['Locations', 'locations', 'fa-map-marker'], ['Transfers', 'stock-transfers', 'fa-exchange']] as [$title, $uri, $icon]) {
                if (! DB::table('admin_menu')->where('uri', $uri)->exists()) {
                    DB::table('admin_menu')->insert(['parent_id' => $shop, 'order' => ++$order, 'title' => $title, 'icon' => $icon, 'uri' => $uri, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        if (DB::table('admin_roles')->exists()) {
            \App\Support\AdminAccess::ensureShopRoles();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_record_batches');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('locations');
    }
};

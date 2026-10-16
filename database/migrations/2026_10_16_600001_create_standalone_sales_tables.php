<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling the standalone edition (docs/STANDALONE_EDITION_PLAN.md §11, §17), after True-Doctor's:
 *  - standalone_products: what is on sale (Platform › Standalone products);
 *  - standalone_orders:   a purchase or a renewal from checkout to verified payment, with the price,
 *                         update months and limits snapshotted when the order was made;
 *  - standalone_licences: one per installation (uuid, the credential the update feed takes);
 *  - standalone_downloads: every release download, from the buyer page or the update feed.
 * Both editions run every migration; only the SaaS ever writes a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standalone_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 190);
            $table->string('slug', 190)->unique();
            $table->text('description')->nullable();
            $table->decimal('price_ugx', 14, 2);
            $table->decimal('price_usd', 12, 2)->nullable();
            $table->decimal('renewal_price_ugx', 14, 2)->nullable(); // empty: renewals by arrangement
            $table->decimal('renewal_price_usd', 12, 2)->nullable();
            $table->unsignedSmallInteger('updates_months')->default(12);
            $table->json('limits')->nullable(); // {max_users, max_locations, max_devices}; null = unlimited
            $table->boolean('white_label')->default(false);
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('standalone_licences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('product_id')->constrained('standalone_products');
            $table->string('customer', 190); // the business the licence is issued to
            $table->string('contact_name', 190)->nullable();
            $table->string('email', 191)->index();
            $table->string('phone', 40)->nullable();
            $table->json('domains');
            $table->json('limits')->nullable();
            $table->boolean('white_label')->default(false);
            $table->date('updates_until');
            $table->string('status', 12)->default('active')->index(); // active | revoked
            $table->string('source', 12); // online | offline
            $table->string('reference', 190)->nullable();
            $table->timestamp('domain_changed_at')->nullable();
            $table->timestamp('last_link_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('standalone_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 64)->unique(); // BPL-…, the Flutterwave tx_ref
            $table->string('kind', 12)->default('purchase'); // purchase | renewal
            $table->foreignId('product_id')->constrained('standalone_products');
            $table->uuid('licence_id')->nullable();
            $table->foreign('licence_id')->references('id')->on('standalone_licences');
            $table->json('buyer'); // {business, name, email, phone, domain}
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->unsignedSmallInteger('months');
            $table->json('limits')->nullable();
            $table->boolean('white_label')->default(false);
            $table->string('status', 12)->default('pending')->index(); // pending | paid | failed | mismatch
            $table->string('gateway_tx_id', 64)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('standalone_downloads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('licence_id');
            $table->foreign('licence_id')->references('id')->on('standalone_licences');
            $table->foreignId('release_id')->constrained('standalone_releases');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_downloads');
        Schema::dropIfExists('standalone_orders');
        Schema::dropIfExists('standalone_licences');
        Schema::dropIfExists('standalone_products');
    }
};

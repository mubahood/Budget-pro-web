<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S6: a phone sends `client_uuid` with POST purchase-orders so a retry after a timeout returns the
 * order it already made instead of a second one. Additive and nullable (web-made orders have none).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_orders') || Schema::hasColumn('purchase_orders', 'client_uuid')) {
            return;
        }
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->string('client_uuid', 36)->nullable()->after('number');
            $t->unique(['company_id', 'client_uuid'], 'purchase_orders_company_client_uuid_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_orders') && Schema::hasColumn('purchase_orders', 'client_uuid')) {
            Schema::table('purchase_orders', function (Blueprint $t) {
                $t->dropUnique('purchase_orders_company_client_uuid_unique');
                $t->dropColumn('client_uuid');
            });
        }
    }
};

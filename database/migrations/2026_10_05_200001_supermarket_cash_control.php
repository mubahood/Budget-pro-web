<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket plan, phase 1 (budget-pro-new/docs/SUPERMARKET_PLAN.md):
 *  A5 supervisor approval: admin_users.pos_pin_hash + the `approvals` log;
 *  E1/E4 cash drops, pickups, paid-in/out and no-sale drawer opens: `cash_movements`;
 *  E2 closed, numbered end-of-day reports: `z_reports`;
 *  E3 blind cash-up: the note/coin count kept on the shift (shifts.cash_count).
 * Additive only: nothing here changes a shop that has not switched the features on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admin_users', 'pos_pin_hash')) {
            Schema::table('admin_users', fn (Blueprint $t) => $t->string('pos_pin_hash')->nullable());
        }
        if (! Schema::hasColumn('shifts', 'cash_count')) {
            Schema::table('shifts', fn (Blueprint $t) => $t->json('cash_count')->nullable());
        }

        if (! Schema::hasTable('approvals')) {
            Schema::create('approvals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('action', 40);
                $t->unsignedBigInteger('requested_by');
                $t->unsignedBigInteger('approved_by');
                $t->unsignedBigInteger('sale_record_id')->nullable();
                $t->decimal('amount', 20, 2)->nullable();
                $t->string('reason', 500)->nullable();
                $t->timestamp('consumed_at')->nullable(); // used once, by the action it approved
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'created_at'], 'approvals_company_created_idx');
            });
        }

        if (! Schema::hasTable('cash_movements')) {
            Schema::create('cash_movements', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid');
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('shift_id');
                $t->string('type', 12); // drop | pickup | paid_in | paid_out | no_sale
                $t->decimal('amount', 20, 2)->default(0);
                $t->string('reason', 500);
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->unsignedBigInteger('financial_record_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->unique(['company_id', 'uuid'], 'cash_movements_company_uuid_unique');
                $t->index(['shift_id', 'type'], 'cash_movements_shift_type_idx');
                $t->index(['company_id', 'created_at'], 'cash_movements_company_created_idx');
            });
        }

        if (! Schema::hasTable('z_reports')) {
            Schema::create('z_reports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('location_key')->default(0); // 0 = the whole shop; else locations.id
                $t->date('business_date');
                $t->string('number', 40);
                $t->json('totals');
                $t->unsignedBigInteger('closed_by');
                $t->timestamp('created_at')->nullable();
                $t->unique(['company_id', 'location_key', 'business_date'], 'z_reports_day_unique');
                $t->unique(['company_id', 'number'], 'z_reports_number_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('z_reports');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('approvals');
        if (Schema::hasColumn('shifts', 'cash_count')) {
            Schema::table('shifts', fn (Blueprint $t) => $t->dropColumn('cash_count'));
        }
        if (Schema::hasColumn('admin_users', 'pos_pin_hash')) {
            Schema::table('admin_users', fn (Blueprint $t) => $t->dropColumn('pos_pin_hash'));
        }
    }
};

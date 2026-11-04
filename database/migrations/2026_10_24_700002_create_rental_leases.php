<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estate Ledger leases (docs/estate-ledger/04-architecture.md §3.1, §5.7–5.8): a tenant in a unit from a date, with its rent, cycle,
 * deposit and commission; the fees beside rent; and the rent's history (a period is billed at the rent in force on
 * its first day).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_leases')) {
            Schema::create('rental_leases', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('number', 20);
                $t->unsignedBigInteger('unit_id');
                $t->unsignedBigInteger('property_id');
                $t->unsignedBigInteger('landlord_id');
                $t->unsignedBigInteger('tenant_id');
                $t->date('start_date');
                $t->date('end_date')->nullable();
                $t->unsignedTinyInteger('cycle_months')->default(1); // 1 | 3 | 6 | 12
                $t->string('anchor', 8)->default('start'); // start | first
                $t->unsignedBigInteger('rent_minor');
                $t->unsignedBigInteger('deposit_minor')->default(0);
                $t->string('deposit_held_by', 10)->default('agency'); // agency | landlord
                $t->string('commission_type', 8)->default('percent'); // percent | flat | none
                $t->unsignedInteger('commission_bp')->default(0);
                $t->unsignedBigInteger('commission_minor')->default(0);
                $t->string('late_fee', 8)->default('default'); // default | off
                $t->date('billed_until')->nullable();
                $t->string('status', 10)->default('active'); // active | notice | ended | cancelled
                $t->date('notice_on')->nullable();
                $t->date('move_out_on')->nullable();
                $t->date('ended_on')->nullable();
                $t->string('end_reason', 255)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->unsignedBigInteger('ended_by_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'number'], 'rental_leases_number_unique');
                $t->index(['company_id', 'status'], 'rental_leases_status_idx');
                $t->index(['company_id', 'unit_id', 'status'], 'rental_leases_unit_idx');
                $t->index(['company_id', 'tenant_id'], 'rental_leases_tenant_idx');
                $t->index(['company_id', 'property_id', 'status'], 'rental_leases_property_idx');
            });
        }

        if (! Schema::hasTable('rental_lease_items')) {
            Schema::create('rental_lease_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('lease_id');
                $t->string('name', 80);
                $t->unsignedBigInteger('amount_minor'); // a month
                $t->string('goes_to', 10)->default('landlord'); // landlord | agency
                $t->boolean('commissionable')->default(false);
                $t->date('starts_on');
                $t->date('ends_on')->nullable();
                $t->boolean('active')->default(true);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'lease_id'], 'rental_lease_items_lease_idx');
            });
        }

        if (! Schema::hasTable('rental_rent_changes')) {
            Schema::create('rental_rent_changes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('lease_id');
                $t->unsignedBigInteger('rent_minor');
                $t->date('effective_from');
                $t->string('reason', 255)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'lease_id', 'effective_from'], 'rental_rent_changes_lease_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['rental_rent_changes', 'rental_lease_items', 'rental_leases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

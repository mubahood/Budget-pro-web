<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estate Ledger money (ADR 0010; docs/estate-ledger/04-architecture.md §3.1, §4, §5): bills (charges), payments and how each was
 * split over the bills (allocations), the balanced journal, landlord payouts and expenses. Integer minor units only.
 * Journal and allocation rows are never updated or deleted: a mistake is a reversal row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_charges')) {
            Schema::create('rental_charges', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('lease_id');
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('unit_id');
                $t->unsignedBigInteger('property_id');
                $t->unsignedBigInteger('landlord_id');
                $t->string('kind', 10); // rent | item | utility | deposit | late_fee | one_off | opening | refund
                $t->unsignedBigInteger('item_id')->nullable();
                $t->unsignedBigInteger('meter_reading_id')->nullable();
                $t->unsignedBigInteger('parent_id')->nullable();
                $t->string('description', 255);
                $t->date('period_start')->nullable();
                $t->date('period_end')->nullable();
                $t->date('due_date');
                $t->unsignedBigInteger('amount_minor');
                $t->unsignedBigInteger('paid_minor')->default(0);
                $t->string('goes_to', 10); // landlord | agency | deposit | tenant
                $t->string('commission_type', 8)->default('none');
                $t->unsignedInteger('commission_bp')->default(0);
                $t->unsignedBigInteger('commission_minor')->default(0);
                $t->string('status', 6)->default('open'); // open | paid | void
                $t->timestamp('voided_at')->nullable();
                $t->unsignedBigInteger('voided_by_id')->nullable();
                $t->string('void_reason', 255)->nullable();
                $t->string('source', 10)->default('auto'); // auto | manual | import | move_out
                $t->string('dedupe_key', 80)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'dedupe_key'], 'rental_charges_dedupe_unique');
                $t->index(['company_id', 'lease_id', 'status', 'due_date'], 'rental_charges_lease_idx');
                $t->index(['company_id', 'landlord_id', 'due_date'], 'rental_charges_landlord_idx');
                $t->index(['company_id', 'status', 'due_date'], 'rental_charges_status_idx');
                $t->index(['company_id', 'property_id', 'due_date'], 'rental_charges_property_idx');
            });
        }

        if (! Schema::hasTable('rental_payments')) {
            Schema::create('rental_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->char('uuid', 36);
                $t->string('number', 20);
                $t->unsignedBigInteger('lease_id');
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('unit_id');
                $t->unsignedBigInteger('property_id');
                $t->unsignedBigInteger('landlord_id');
                $t->unsignedBigInteger('amount_minor');
                $t->string('method', 14); // cash | bank | mobile_money | cheque | deposit | waiver | opening
                $t->string('reference', 100)->nullable();
                $t->date('paid_on');
                $t->unsignedBigInteger('received_by_id')->nullable();
                $t->string('channel', 8)->default('web'); // web | app | portal | import | system
                $t->string('notes', 255)->nullable();
                $t->unsignedBigInteger('unallocated_minor')->default(0);
                $t->unsignedBigInteger('waived_charge_id')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->unsignedBigInteger('reversed_by_id')->nullable();
                $t->string('reverse_reason', 255)->nullable();
                $t->string('device_id', 64)->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'uuid'], 'rental_payments_uuid_unique');
                $t->unique(['company_id', 'number'], 'rental_payments_number_unique');
                $t->index(['company_id', 'lease_id'], 'rental_payments_lease_idx');
                $t->index(['company_id', 'paid_on'], 'rental_payments_date_idx');
                $t->index(['company_id', 'landlord_id', 'paid_on'], 'rental_payments_landlord_idx');
            });
        }

        if (! Schema::hasTable('rental_allocations')) {
            Schema::create('rental_allocations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('payment_id');
                $t->unsignedBigInteger('charge_id');
                $t->string('source', 6)->default('direct'); // direct | credit
                $t->bigInteger('amount_minor');
                $t->bigInteger('commission_minor')->default(0);
                $t->bigInteger('landlord_minor')->default(0);
                $t->bigInteger('agency_minor')->default(0);
                $t->bigInteger('deposit_minor')->default(0);
                $t->unsignedBigInteger('reverses_id')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->date('applied_on');
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'payment_id'], 'rental_allocations_payment_idx');
                $t->index(['company_id', 'charge_id'], 'rental_allocations_charge_idx');
            });
        }

        if (! Schema::hasTable('rental_entries')) {
            Schema::create('rental_entries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->char('group_id', 36);
                $t->string('type', 24);
                $t->date('occurred_on');
                $t->string('method', 14)->nullable();
                foreach (['cash', 'landlord', 'deposit', 'credit', 'income', 'expense', 'equity'] as $col) {
                    $t->bigInteger("{$col}_minor")->default(0);
                }
                foreach (['landlord', 'property', 'unit', 'lease', 'tenant', 'payment', 'allocation', 'charge', 'payout', 'expense'] as $ref) {
                    $t->unsignedBigInteger("{$ref}_id")->nullable();
                }
                $t->string('description', 255)->nullable();
                $t->unsignedBigInteger('reverses_id')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'occurred_on'], 'rental_entries_date_idx');
                $t->index(['company_id', 'landlord_id', 'occurred_on'], 'rental_entries_landlord_idx');
                $t->index(['company_id', 'lease_id'], 'rental_entries_lease_idx');
                $t->index(['company_id', 'payment_id'], 'rental_entries_payment_idx');
                $t->index(['company_id', 'type'], 'rental_entries_type_idx');
                $t->index(['company_id', 'group_id'], 'rental_entries_group_idx');
            });
        }

        if (! Schema::hasTable('rental_payouts')) {
            Schema::create('rental_payouts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('number', 20);
                $t->unsignedBigInteger('landlord_id');
                $t->unsignedBigInteger('amount_minor');
                $t->string('method', 14);
                $t->string('reference', 100)->nullable();
                $t->date('paid_on');
                $t->date('period_from')->nullable();
                $t->date('period_to')->nullable();
                $t->string('notes', 255)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->unsignedBigInteger('reversed_by_id')->nullable();
                $t->string('reverse_reason', 255)->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'number'], 'rental_payouts_number_unique');
                $t->index(['company_id', 'landlord_id', 'paid_on'], 'rental_payouts_landlord_idx');
            });
        }

        if (! Schema::hasTable('rental_expenses')) {
            Schema::create('rental_expenses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('number', 20);
                $t->unsignedBigInteger('property_id')->nullable();
                $t->unsignedBigInteger('unit_id')->nullable();
                $t->unsignedBigInteger('landlord_id')->nullable();
                $t->string('category', 60);
                $t->string('description', 255)->nullable();
                $t->string('vendor', 150)->nullable();
                $t->unsignedBigInteger('amount_minor');
                $t->string('method', 14);
                $t->date('paid_on');
                $t->string('charged_to', 10); // landlord | agency
                $t->string('receipt_uuid', 36)->nullable();
                $t->unsignedBigInteger('maintenance_id')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->unsignedBigInteger('reversed_by_id')->nullable();
                $t->string('reverse_reason', 255)->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'number'], 'rental_expenses_number_unique');
                $t->index(['company_id', 'paid_on'], 'rental_expenses_date_idx');
                $t->index(['company_id', 'property_id'], 'rental_expenses_property_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['rental_expenses', 'rental_payouts', 'rental_entries', 'rental_allocations', 'rental_payments', 'rental_charges'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

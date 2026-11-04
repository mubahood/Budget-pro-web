<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estate Ledger (the rentals module, docs/estate-ledger/04-architecture.md §3.1): the account's settings row (also its money
 * lock, ADR 0010 §6), landlords, properties, the caretakers assigned to them, units and tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_settings')) {
            Schema::create('rental_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->unique();
                $t->string('mode', 10)->default('agency'); // agency | owner
                $t->char('currency', 3)->default('UGX');
                $t->unsignedTinyInteger('bill_days_before')->default(5);
                $t->unsignedTinyInteger('grace_days')->default(5);
                $t->string('anchor', 8)->default('start'); // start | first
                $t->boolean('prorate_first')->default(true);
                $t->string('commission_type', 8)->default('percent'); // percent | flat | none
                $t->unsignedInteger('commission_bp')->default(1000);
                $t->unsignedBigInteger('commission_minor')->default(0);
                $t->boolean('commission_on_items')->default(false);
                $t->unsignedTinyInteger('deposit_months')->default(1);
                $t->string('deposit_held_by', 10)->default('agency'); // agency | landlord
                $t->string('late_fee_type', 8)->default('none'); // none | flat | percent
                $t->unsignedBigInteger('late_fee_minor')->default(0);
                $t->unsignedInteger('late_fee_bp')->default(0);
                $t->string('late_fee_to', 10)->default('agency'); // agency | landlord
                $t->text('payment_instructions')->nullable();
                $t->string('receipt_footer', 255)->nullable();
                $t->json('messages')->nullable();
                foreach (['receipt', 'lease', 'payout', 'expense', 'ticket', 'tenant', 'landlord'] as $seq) {
                    $t->unsignedInteger("seq_{$seq}")->default(0);
                }
                $t->timestamp('setup_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('rental_landlords')) {
            Schema::create('rental_landlords', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('code', 20)->nullable();
                $t->string('name', 150);
                $t->string('phone', 40)->nullable();
                $t->string('phone_e164', 20)->nullable();
                $t->string('email', 150)->nullable();
                $t->string('address', 255)->nullable();
                $t->text('id_number')->nullable(); // DataCrypt
                $t->string('payout_method', 16)->default('bank'); // cash | bank | mobile_money
                $t->text('payout_details')->nullable();
                $t->string('commission_type', 8)->nullable();
                $t->unsignedInteger('commission_bp')->nullable();
                $t->unsignedBigInteger('commission_minor')->nullable();
                $t->boolean('is_self')->default(false);
                $t->text('notes')->nullable();
                $t->string('status', 10)->default('active'); // active | inactive
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'status'], 'rental_landlords_status_idx');
                $t->index(['company_id', 'phone_e164'], 'rental_landlords_phone_idx');
            });
        }

        if (! Schema::hasTable('rental_properties')) {
            Schema::create('rental_properties', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('landlord_id');
                $t->string('code', 20)->nullable();
                $t->string('name', 150);
                $t->string('kind', 12)->default('residential'); // residential | commercial | mixed | land
                $t->string('address', 255)->nullable();
                $t->string('area', 100)->nullable();
                $t->string('district', 100)->nullable();
                $t->decimal('lat', 10, 7)->nullable();
                $t->decimal('lng', 10, 7)->nullable();
                $t->string('photo_uuid', 36)->nullable();
                $t->string('commission_type', 8)->nullable();
                $t->unsignedInteger('commission_bp')->nullable();
                $t->unsignedBigInteger('commission_minor')->nullable();
                $t->string('deposit_held_by', 10)->nullable();
                $t->unsignedBigInteger('water_rate_minor')->default(0);
                $t->unsignedBigInteger('power_rate_minor')->default(0);
                $t->text('notes')->nullable();
                $t->string('status', 10)->default('active'); // active | archived
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'status'], 'rental_properties_status_idx');
                $t->index(['company_id', 'landlord_id'], 'rental_properties_landlord_idx');
            });
        }

        if (! Schema::hasTable('rental_property_staff')) {
            Schema::create('rental_property_staff', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('property_id');
                $t->unsignedBigInteger('user_id');
                $t->timestamps();
                $t->unique(['property_id', 'user_id'], 'rental_property_staff_unique');
                $t->index(['company_id', 'user_id'], 'rental_property_staff_user_idx');
            });
        }

        if (! Schema::hasTable('rental_units')) {
            Schema::create('rental_units', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('property_id');
                $t->string('code', 40);
                $t->string('kind', 20)->default('single_room');
                $t->string('floor', 20)->nullable();
                $t->string('block', 20)->nullable();
                $t->unsignedTinyInteger('bedrooms')->nullable();
                $t->unsignedTinyInteger('bathrooms')->nullable();
                $t->unsignedInteger('size_sqm')->nullable();
                $t->unsignedBigInteger('rent_minor')->default(0);
                $t->unsignedBigInteger('deposit_minor')->nullable();
                $t->unsignedTinyInteger('cycle_months')->default(1);
                $t->string('status', 12)->default('available'); // available | reserved | maintenance | inactive
                $t->string('water_meter', 40)->nullable();
                $t->string('power_meter', 40)->nullable();
                $t->text('notes')->nullable();
                $t->string('photo_uuid', 36)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'property_id', 'code'], 'rental_units_code_unique');
                $t->index(['company_id', 'status'], 'rental_units_status_idx');
            });
        }

        if (! Schema::hasTable('rental_tenants')) {
            Schema::create('rental_tenants', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('code', 20)->nullable();
                $t->string('kind', 10)->default('person'); // person | company
                $t->string('name', 150);
                $t->string('phone', 40)->nullable();
                $t->string('phone_e164', 20)->nullable();
                $t->string('alt_phone', 40)->nullable();
                $t->string('email', 150)->nullable();
                $t->string('id_type', 16)->nullable(); // national_id | passport | driving_permit | company_reg | other
                $t->text('id_number')->nullable(); // DataCrypt
                $t->string('occupation', 100)->nullable();
                $t->string('employer', 150)->nullable();
                $t->string('next_of_kin_name', 150)->nullable();
                $t->string('next_of_kin_phone', 40)->nullable();
                $t->string('photo_uuid', 36)->nullable();
                $t->text('notes')->nullable();
                $t->string('status', 10)->default('active'); // active | former
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'status'], 'rental_tenants_status_idx');
                $t->index(['company_id', 'phone_e164'], 'rental_tenants_phone_idx');
                $t->index(['company_id', 'name'], 'rental_tenants_name_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['rental_tenants', 'rental_units', 'rental_property_staff', 'rental_properties', 'rental_landlords', 'rental_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

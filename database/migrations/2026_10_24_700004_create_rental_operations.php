<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estate Ledger operations (docs/estate-ledger/04-architecture.md §3.1): maintenance requests, meter readings, the collections
 * follow-up log, tenants' and landlords' own sign-ins (never staff logins, KR-16), documents and the audit trail.
 * Then the module's foreign keys to companies and parents (SchemaIntegrity::FOREIGN_KEYS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_maintenance')) {
            Schema::create('rental_maintenance', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->char('uuid', 36);
                $t->string('number', 20);
                $t->unsignedBigInteger('property_id');
                $t->unsignedBigInteger('unit_id')->nullable();
                $t->unsignedBigInteger('lease_id')->nullable();
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->string('title', 150);
                $t->text('details')->nullable();
                $t->string('priority', 8)->default('normal'); // low | normal | high | urgent
                $t->string('status', 12)->default('open'); // open | in_progress | done | cancelled
                $t->string('reported_by', 10)->default('staff'); // staff | tenant | landlord
                $t->unsignedBigInteger('reported_by_id')->nullable();
                $t->unsignedBigInteger('assigned_to_id')->nullable();
                $t->string('assignee_name', 150)->nullable();
                $t->json('photos')->nullable();
                $t->unsignedBigInteger('cost_minor')->nullable();
                $t->unsignedBigInteger('expense_id')->nullable();
                $t->date('resolved_on')->nullable();
                $t->text('resolution')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'uuid'], 'rental_maintenance_uuid_unique');
                $t->unique(['company_id', 'number'], 'rental_maintenance_number_unique');
                $t->index(['company_id', 'status'], 'rental_maintenance_status_idx');
                $t->index(['company_id', 'property_id'], 'rental_maintenance_property_idx');
            });
        }

        if (! Schema::hasTable('rental_meter_readings')) {
            Schema::create('rental_meter_readings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->char('uuid', 36);
                $t->unsignedBigInteger('unit_id');
                $t->unsignedBigInteger('lease_id')->nullable();
                $t->string('utility', 6); // water | power
                $t->decimal('reading', 14, 3);
                $t->decimal('previous', 14, 3)->default(0);
                $t->decimal('used', 14, 3)->default(0);
                $t->unsignedBigInteger('rate_minor')->default(0);
                $t->unsignedBigInteger('amount_minor')->default(0);
                $t->date('read_on');
                $t->boolean('reset')->default(false);
                $t->unsignedBigInteger('charge_id')->nullable();
                $t->string('photo_uuid', 36)->nullable();
                $t->unsignedBigInteger('recorded_by_id')->nullable();
                $t->string('notes', 255)->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'uuid'], 'rental_meter_readings_uuid_unique');
                $t->index(['company_id', 'unit_id', 'utility', 'read_on'], 'rental_meter_readings_unit_idx');
            });
        }

        if (! Schema::hasTable('rental_follow_ups')) {
            Schema::create('rental_follow_ups', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('lease_id');
                $t->unsignedBigInteger('tenant_id');
                $t->string('channel', 10); // whatsapp | sms | call | visit | note
                $t->string('note', 500)->nullable();
                $t->unsignedBigInteger('owed_minor')->default(0);
                $t->date('promise_on')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'lease_id'], 'rental_follow_ups_lease_idx');
            });
        }

        if (! Schema::hasTable('rental_logins')) {
            Schema::create('rental_logins', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('subject_type', 10); // tenant | landlord
                $t->unsignedBigInteger('subject_id');
                $t->string('email', 150)->nullable();
                $t->string('phone_e164', 20)->nullable();
                $t->string('password')->nullable();
                $t->unsignedTinyInteger('failures')->default(0);
                $t->timestamp('locked_until')->nullable();
                $t->timestamp('disabled_at')->nullable();
                $t->timestamp('last_login_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'subject_type', 'subject_id'], 'rental_logins_subject_unique');
                $t->index('email', 'rental_logins_email_idx');
                $t->index('phone_e164', 'rental_logins_phone_idx');
            });
        }

        if (! Schema::hasTable('rental_documents')) {
            Schema::create('rental_documents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('owner_type', 10); // tenant | lease | property | landlord
                $t->unsignedBigInteger('owner_id');
                $t->string('kind', 10)->default('other'); // agreement | id | photo | other
                $t->string('name', 150);
                $t->char('file_uuid', 36);
                $t->unsignedBigInteger('uploaded_by_id')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'owner_type', 'owner_id'], 'rental_documents_owner_idx');
            });
        }

        if (! Schema::hasTable('rental_activities')) {
            Schema::create('rental_activities', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('actor_type', 10)->default('staff'); // staff | tenant | landlord | system
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('action', 60);
                $t->string('subject_type', 30)->nullable();
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->string('summary', 255);
                $t->json('meta')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'created_at'], 'rental_activities_date_idx');
                $t->index(['company_id', 'subject_type', 'subject_id'], 'rental_activities_subject_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        foreach (['rental_activities', 'rental_documents', 'rental_logins', 'rental_follow_ups', 'rental_meter_readings', 'rental_maintenance'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

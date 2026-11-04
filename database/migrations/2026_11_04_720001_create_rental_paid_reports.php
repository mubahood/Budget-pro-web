<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "I have paid": what a tenant says they paid, waiting for the staff. A row here is a claim, never money: it touches no
 * bill, journal row or balance until a person confirms it, which records a real receipt (Payments::receive) under the
 * row's own uuid, so confirming twice can never make two receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_paid_reports')) {
            return;
        }
        Schema::create('rental_paid_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('uuid', 36);
            $t->unsignedBigInteger('login_id')->nullable();
            $t->unsignedBigInteger('lease_id');
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('unit_id');
            $t->unsignedBigInteger('property_id');
            $t->unsignedBigInteger('amount_minor');
            $t->string('method', 16);
            $t->string('reference', 100)->nullable();
            $t->date('paid_on');
            $t->string('note', 255)->nullable();
            $t->string('photo_uuid', 36)->nullable();
            $t->string('status', 12)->default('pending'); // pending | confirmed | declined
            $t->unsignedBigInteger('payment_id')->nullable();
            $t->unsignedBigInteger('decided_by_id')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decline_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'uuid'], 'rental_paid_reports_uuid_unique');
            $t->index(['company_id', 'status', 'property_id'], 'rental_paid_reports_queue_idx');
            $t->index(['company_id', 'tenant_id', 'id'], 'rental_paid_reports_tenant_idx');
            $t->index(['company_id', 'reference'], 'rental_paid_reports_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_paid_reports');
    }
};

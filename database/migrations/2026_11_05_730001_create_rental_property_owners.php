<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who owned a property from when (LC-01): one row per handover and one for the owner before the first. A property with no row is its current
 * landlord's since the beginning, so nothing here needs a backfill. Additive; a handover dated later waits with applied_at empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_property_owners')) {
            return;
        }
        Schema::create('rental_property_owners', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('property_id');
            $t->unsignedBigInteger('landlord_id');
            $t->date('from_date');
            $t->date('to_date')->nullable();
            $t->string('reason', 255)->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancelled_by_id')->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'property_id', 'from_date'], 'rental_property_owners_from_idx');
            $t->index(['company_id', 'landlord_id'], 'rental_property_owners_landlord_idx');
        });
    }

    public function down(): void
    {
        // Additive and live: nothing to take back.
    }
};

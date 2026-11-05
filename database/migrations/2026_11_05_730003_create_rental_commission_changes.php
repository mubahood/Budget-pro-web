<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lease's commission from a date (LC-15), as rental_rent_changes is for its rent: append only. A lease with no row is on its own columns; the
 * first change writes a starting row from them. Bills already raised keep the rule they carry. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_commission_changes')) {
            return;
        }
        Schema::create('rental_commission_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('lease_id');
            $t->string('commission_type', 8);
            $t->unsignedInteger('commission_bp')->default(0);
            $t->unsignedBigInteger('commission_minor')->default(0);
            $t->date('effective_from');
            $t->string('reason', 255)->nullable();
            $t->uuid('batch_uuid')->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['company_id', 'lease_id', 'effective_from'], 'rental_commission_changes_lease_idx');
        });
    }

    public function down(): void
    {
        // Additive and live: nothing to take back.
    }
};

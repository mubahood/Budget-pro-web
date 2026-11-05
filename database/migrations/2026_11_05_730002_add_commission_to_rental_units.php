<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A commission rule on a unit (LC-14): empty means the unit follows its property. New leases read it; bills and live leases do not change. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_units', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_units', 'commission_type')) {
                $t->string('commission_type', 8)->nullable();
            }
            if (! Schema::hasColumn('rental_units', 'commission_bp')) {
                $t->unsignedInteger('commission_bp')->nullable();
            }
            if (! Schema::hasColumn('rental_units', 'commission_minor')) {
                $t->unsignedBigInteger('commission_minor')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Additive and live: nothing to take back.
    }
};

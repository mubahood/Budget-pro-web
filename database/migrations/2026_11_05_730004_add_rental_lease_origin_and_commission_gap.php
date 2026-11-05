<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lease's origin (LC-22): a sitting tenant the agency took over, or a new one it found. Leases already there read as new, the default; only the
 * import says sitting. And how far below the account's usual rate a landlord's effective rate must fall to be listed on the commission report (LC-16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_leases', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_leases', 'origin')) {
                $t->string('origin', 8)->default('new');
            }
        });
        Schema::table('rental_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_settings', 'commission_gap_bp')) {
                $t->unsignedInteger('commission_gap_bp')->default(300);
            }
        });
    }

    public function down(): void
    {
        // Additive and live: nothing to take back.
    }
};

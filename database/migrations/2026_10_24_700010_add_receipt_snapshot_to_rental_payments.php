<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a receipt said when it was issued (ADR 0010: a reprint is the same paper): the names, figures and words of
 * Payments\Receipt, written once inside the lock that posted the payment, and read back on every reprint. Null on a
 * payment made before this column existed, whose receipt keeps reading the live records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_payments') && ! Schema::hasColumn('rental_payments', 'receipt_snapshot')) {
            Schema::table('rental_payments', function (Blueprint $t) {
                $t->json('receipt_snapshot')->nullable()->after('device_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_payments', 'receipt_snapshot')) {
            Schema::table('rental_payments', function (Blueprint $t) {
                $t->dropColumn('receipt_snapshot');
            });
        }
    }
};

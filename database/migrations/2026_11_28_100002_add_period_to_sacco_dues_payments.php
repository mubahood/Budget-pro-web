<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The period a dues payment was given for (optional; the phone sends it). Unnamed payments fill the oldest periods first. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sacco_dues_payments', 'period_key')) {
            Schema::table('sacco_dues_payments', fn (Blueprint $t) => $t->string('period_key', 10)->nullable()->after('member_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sacco_dues_payments', 'period_key')) {
            Schema::table('sacco_dues_payments', fn (Blueprint $t) => $t->dropColumn('period_key'));
        }
    }
};

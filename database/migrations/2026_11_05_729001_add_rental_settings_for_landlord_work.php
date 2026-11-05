<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The settings RentalsModule now knows of: whether rent paid ahead is held or billed by default, the account codes for the accountant's
 * file, and the numbering of statements and payout runs. Additive; nothing reads them until their build package lands.
 *
 * Migrations of the landlord and commission round (docs/estate-ledger/04-landlord-and-commission-tasks.md §7 in the portal's repo) take
 * their numbers from one range per build package, under 2026_11_05_: this seam 729xxx, A 730xxx, B 731xxx, C 732xxx, D 733xxx, E 734xxx.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_settings', 'ahead_default')) {
                $t->string('ahead_default', 8)->default('hold');
            }
            if (! Schema::hasColumn('rental_settings', 'export_accounts')) {
                $t->json('export_accounts')->nullable();
            }
            if (! Schema::hasColumn('rental_settings', 'seq_statement')) {
                $t->unsignedInteger('seq_statement')->default(0);
            }
            if (! Schema::hasColumn('rental_settings', 'seq_payout_run')) {
                $t->unsignedInteger('seq_payout_run')->default(0);
            }
        });
    }

    public function down(): void
    {
        // Additive and live: nothing to take back.
    }
};

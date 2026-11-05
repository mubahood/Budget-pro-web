<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A landlord's limit on repairs (LC-17): "ask me above this", the quote on a repair and the landlord's answer to it, and the office's reason when
 * it paid above the limit with no answer. Additive and safe on the live database: every column is empty or `none` until someone uses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_landlords', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_landlords', 'expense_limit_minor')) {
                $t->bigInteger('expense_limit_minor')->nullable();
            }
        });
        Schema::table('rental_maintenance', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_maintenance', 'quote_minor')) {
                $t->bigInteger('quote_minor')->nullable();
            }
            if (! Schema::hasColumn('rental_maintenance', 'approval_status')) {
                $t->string('approval_status', 10)->default('none'); // none | asked | yes | no
            }
            if (! Schema::hasColumn('rental_maintenance', 'approval_via')) {
                $t->string('approval_via', 10)->nullable(); // portal | phone | whatsapp | other
            }
            if (! Schema::hasColumn('rental_maintenance', 'approval_note')) {
                $t->string('approval_note', 500)->nullable();
            }
            if (! Schema::hasColumn('rental_maintenance', 'approval_at')) {
                $t->timestamp('approval_at')->nullable();
            }
            if (! Schema::hasColumn('rental_maintenance', 'approval_by_id')) {
                $t->unsignedBigInteger('approval_by_id')->nullable(); // the staff login, or the landlord's own sign-in when approval_via is portal
            }
            if (! Schema::hasColumn('rental_maintenance', 'approval_landlord_id')) {
                $t->unsignedBigInteger('approval_landlord_id')->nullable(); // whose answer it is: a handover leaves it with the old landlord
            }
        });
        Schema::table('rental_expenses', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_expenses', 'over_limit_reason')) {
                $t->string('over_limit_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Additive and live: nothing to take back.
    }
};

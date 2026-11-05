<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A follow-up written on a phone with no network carries its own id, so sending it again is the same follow-up (the phone's outbox).
 * Null for every follow-up already written, and for any made without one: a unique index ignores nulls, so nothing old changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_follow_ups', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_follow_ups', 'uuid')) {
                $t->string('uuid', 36)->nullable()->after('id');
                $t->unique(['company_id', 'uuid'], 'rental_follow_ups_uuid_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_follow_ups', function (Blueprint $t) {
            if (Schema::hasColumn('rental_follow_ups', 'uuid')) {
                $t->dropUnique('rental_follow_ups_uuid_unique');
                $t->dropColumn('uuid');
            }
        });
    }
};

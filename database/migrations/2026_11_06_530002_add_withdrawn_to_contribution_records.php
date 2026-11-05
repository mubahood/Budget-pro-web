<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PB-04: a pledge can be withdrawn with a reason. Withdrawing also sets the existing tombstone (is_deleted = 1), so
 * older phones remove it and every query that filters on is_deleted leaves it out; these columns are the trail
 * and let it be put back. New nullable columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contribution_records', function (Blueprint $t) {
            if (! Schema::hasColumn('contribution_records', 'withdrawn_at')) {
                $t->timestamp('withdrawn_at')->nullable();
            }
            if (! Schema::hasColumn('contribution_records', 'withdrawn_by_id')) {
                $t->unsignedBigInteger('withdrawn_by_id')->nullable(); // admin_users.id
            }
            if (! Schema::hasColumn('contribution_records', 'withdrawn_reason')) {
                $t->string('withdrawn_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contribution_records', function (Blueprint $t) {
            foreach (['withdrawn_at', 'withdrawn_by_id', 'withdrawn_reason'] as $column) {
                if (Schema::hasColumn('contribution_records', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};

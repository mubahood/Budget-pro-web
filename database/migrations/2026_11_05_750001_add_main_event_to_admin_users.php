<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The event a person chose as their main one, "Set as my main event" in Pledge & Budget (budget-pro-new App\Shop\MainEvent,
 * docs/pledge-budget/02-owner-brief.md OB-02). Empty unless the person chooses: the main event is worked out when it is empty.
 * No foreign key: an event removed or out of sight is simply ignored and the automatic choice takes over. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admin_users', 'main_event_id')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->unsignedBigInteger('main_event_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admin_users', 'main_event_id')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->dropColumn('main_event_id');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The pledger's phone number, optional, in E.164 (App\Support\Phone), for reminders (roadmap 02 §6, 04 §4). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contribution_records', 'phone')) {
            Schema::table('contribution_records', function (Blueprint $table) {
                $table->string('phone', 20)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contribution_records', 'phone')) {
            Schema::table('contribution_records', function (Blueprint $table) {
                $table->dropColumn('phone');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| One business per standalone installation (docs/STANDALONE_EDITION_PLAN.md §6): the only company
| holds slot 1, and the unique index refuses a second. NULL on the SaaS, where any number of
| companies share the table (a unique index allows any number of NULLs).
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'standalone_slot')) {
            return;
        }
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('standalone_slot')->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('companies', 'standalone_slot')) {
            return;
        }
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['standalone_slot']);
            $table->dropColumn('standalone_slot');
        });
    }
};

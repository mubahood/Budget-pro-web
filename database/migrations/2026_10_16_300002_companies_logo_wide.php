<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The buyer's brand (docs/STANDALONE_EDITION_PLAN.md §15): a wide logo (the name in it) for the
| sign-in page, emails and error pages, beside the square `logo`. The colour reuses `color` and the
| tagline reuses `slogan`.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'logo_wide')) {
            return;
        }
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_wide')->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'logo_wide')) {
            Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('logo_wide'));
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Supermarket features and their settings, per shop (App\Support\StoreFeatures). Null = defaults. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'store_settings')) {
            Schema::table('companies', fn (Blueprint $t) => $t->json('store_settings')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'store_settings')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('store_settings'));
        }
    }
};

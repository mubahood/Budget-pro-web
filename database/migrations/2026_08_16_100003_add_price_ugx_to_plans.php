<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an explicit UGX price to plans so Ugandan customers are billed a fixed
 * local amount (via Flutterwave mobile money) with no runtime FX conversion,
 * while international customers pay the USD `price` by card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_ugx', 14, 2)->default(0)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('price_ugx');
        });
    }
};

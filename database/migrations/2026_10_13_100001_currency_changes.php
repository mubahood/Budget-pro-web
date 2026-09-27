<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every change of a shop's currency, and how it was done (App\Services\Shop\CurrencyChangeService). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currency_changes')) {
            Schema::create('currency_changes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('from_currency', 8);
                $t->string('to_currency', 8);
                $t->string('mode', 10); // relabel | convert
                $t->decimal('rate', 20, 10)->nullable(); // 1 from = rate to
                $t->json('rows')->nullable(); // table => rows changed
                $t->unsignedBigInteger('changed_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_changes');
    }
};

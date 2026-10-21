<?php

use App\Services\Sacco\Calendar\PublicHolidays;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P2.1 (docs/akiba/04-architecture.md §3.4, AK-51): the public holidays working days skip (the loan
 * cooling-off, return due dates). A platform table, not a SACCO's: one country's holidays are the same for every
 * SACCO in it. Fixed-date holidays are known; movable feasts (Easter, both Eids) come from the data file and the
 * Eids are marked estimates until the platform admin confirms the gazetted date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_public_holidays')) {
            Schema::create('sacco_public_holidays', function (Blueprint $t) {
                $t->id();
                $t->char('country', 2);
                $t->date('date');
                $t->string('name', 120);
                $t->string('kind', 10)->default('fixed'); // fixed | movable
                $t->boolean('is_estimate')->default(false); // an Eid not yet gazetted
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamps();

                $t->unique(['country', 'date'], 'sacco_public_holidays_country_date_unique');
            });
        }
        $year = (int) now()->year;
        foreach (range($year - 2, $year + 2) as $y) {
            (new PublicHolidays())->seed('UG', $y);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_public_holidays');
    }
};

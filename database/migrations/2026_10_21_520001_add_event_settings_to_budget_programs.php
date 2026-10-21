<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An event's settings (App\Services\Budget\EventSettings): what kind of event it is and which budget template
 * started it, where it is, the event's own settings (message templates, amount presets, how money is shown) and
 * where pledgers pay (mobile money numbers, bank). All optional, so older apps and the classic screens carry on as
 * before. The pledge groups use the existing `groups` column (unused until now). Plain text columns holding JSON,
 * so sync sends them as strings to the phones.
 */
return new class extends Migration
{
    private const COLUMNS = ['event_type', 'template_key', 'venue', 'settings', 'payment_details'];

    public function up(): void
    {
        Schema::table('budget_programs', function (Blueprint $table) {
            if (! Schema::hasColumn('budget_programs', 'event_type')) {
                $table->string('event_type', 40)->nullable();
            }
            if (! Schema::hasColumn('budget_programs', 'template_key')) {
                $table->string('template_key', 60)->nullable();
            }
            if (! Schema::hasColumn('budget_programs', 'venue')) {
                $table->string('venue', 191)->nullable();
            }
            if (! Schema::hasColumn('budget_programs', 'settings')) {
                $table->text('settings')->nullable();
            }
            if (! Schema::hasColumn('budget_programs', 'payment_details')) {
                $table->text('payment_details')->nullable();
            }
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('budget_programs', $c)));
        if ($present !== []) {
            Schema::table('budget_programs', function (Blueprint $table) use ($present) {
                $table->dropColumn($present);
            });
        }
    }
};

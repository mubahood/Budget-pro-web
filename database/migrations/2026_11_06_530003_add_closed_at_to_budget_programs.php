<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PB-06 (decision D5): when an event closed, so a phone's work made before that moment is still accepted and later
 * work is refused. Set when an event closes, cleared when it reopens (EventClosure::stamp). Backfill: events
 * already closed take their last change as the moment they closed. A new nullable column; nothing else is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('budget_programs', 'closed_at')) {
            Schema::table('budget_programs', fn (Blueprint $t) => $t->timestamp('closed_at')->nullable());
        }
        DB::table('budget_programs')->whereNull('closed_at')->whereNotNull('status')->where('status', '!=', '')->where('status', '!=', 'Active')
            ->update(['closed_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'), 'updated_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('budget_programs', 'closed_at')) {
            Schema::table('budget_programs', fn (Blueprint $t) => $t->dropColumn('closed_at'));
        }
    }
};

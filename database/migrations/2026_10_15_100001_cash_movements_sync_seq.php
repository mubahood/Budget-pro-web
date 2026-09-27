<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phones pull cash movements (drops, paid-in/out, no-sale) like every other event table: by
 * `server_seq`. Additive: the column, its index, and a seq for the rows already there.
 * CashMovement takes the next seq when it is created; CurrencyChangeService re-sequences it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_movements') || Schema::hasColumn('cash_movements', 'server_seq')) {
            return;
        }
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->unsignedBigInteger('server_seq')->nullable()->after('financial_record_id');
            $t->index(['company_id', 'server_seq'], 'cash_movements_company_seq');
        });
        $ids = DB::table('cash_movements')->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        $first = \App\Support\Sync\SyncSequence::reserve($ids->count());
        foreach ($ids->values() as $i => $id) {
            DB::table('cash_movements')->where('id', $id)->update(['server_seq' => $first + $i]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cash_movements') && Schema::hasColumn('cash_movements', 'server_seq')) {
            Schema::table('cash_movements', function (Blueprint $t) {
                $t->dropIndex('cash_movements_company_seq');
                $t->dropColumn('server_seq');
            });
        }
    }
};

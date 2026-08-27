<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pushed child row (daily record, mortality/health/vaccination event)
     * whose parent batch hasn't synced yet must never hard-fail — the
     * BACKEND_API_MASTER_TASKS.md §8.2 rule is "never hard-fail a push
     * solely because the referenced parent uuid isn't present yet". These 4
     * tables had NOT NULL batch_id, which would throw a DB exception on
     * exactly that case. The web admin form still requires picking a batch
     * (validated at the form layer) — this only relaxes the DB constraint
     * for the sync-write path.
     */
    private array $tables = [
        'poultry_daily_records', 'poultry_mortality_events', 'poultry_health_events', 'poultry_vaccination_events',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('batch_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('batch_id')->nullable(false)->change();
            });
        }
    }
};

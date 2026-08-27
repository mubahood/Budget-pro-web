<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Adds the mobile-sync columns (BACKEND_API_MASTER_TASKS.md §8.2) to the
     * 11 tenant-scoped poultry tables built for the web admin. `id` stays the
     * internal PK the admin CRUD already uses; `uuid` is the business key the
     * offline-first mobile app matches on. Backfills a uuid for any row
     * created via the web admin before this migration ran.
     */
    private array $tables = [
        'poultry_batches', 'poultry_feed_types', 'poultry_customers', 'poultry_daily_records',
        'poultry_feed_stock', 'poultry_sales', 'poultry_expenses', 'poultry_egg_transactions',
        'poultry_mortality_events', 'poultry_health_events', 'poultry_vaccination_events',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('uuid')->nullable()->after('id');
                $t->unsignedBigInteger('client_created_at')->nullable();
                $t->unsignedBigInteger('client_updated_at')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->boolean('is_deleted')->default(false);
                $t->string('entered_by')->nullable();
            });

            foreach (DB::table($table)->whereNull('uuid')->select('id')->get() as $row) {
                DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
            }

            // A null client_updated_at would never match a pull's `> since`
            // filter once since > 0, silently hiding pre-existing rows from
            // every device forever. Backfill from the row's own real
            // created/updated timestamps so nothing is ever unreachable.
            DB::statement("update `{$table}` set client_created_at = unix_timestamp(created_at) * 1000 where client_created_at is null");
            DB::statement("update `{$table}` set client_updated_at = unix_timestamp(updated_at) * 1000 where client_updated_at is null");

            // Deliberately (company_id, uuid), NOT a plain global unique on
            // uuid — accidental collisions between two companies' generated
            // UUIDs are practically impossible, but a plain global unique
            // means company B pushing a *guessed* uuid from company A hits
            // a raw duplicate-key DB error instead of cleanly creating its
            // own isolated row. Tenant isolation must hold even when a
            // malicious client deliberately reuses another tenant's uuid.
            Schema::table($table, function (Blueprint $t) {
                $t->unique(['company_id', 'uuid']);
                $t->index(['company_id', 'is_deleted']);
            });
        }

        // Round-trip fidelity: mobile's local feed_stock row carries a
        // ref_uuid linking to an expense/daily record it was generated from.
        // The web admin has no use for it, but a pull must return exactly
        // what a push sent or the mobile copy silently loses the link.
        Schema::table('poultry_feed_stock', function (Blueprint $t) {
            $t->string('ref_uuid', 64)->nullable();
        });
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            // A composite index/unique survives dropping just one of its
            // columns — both must be dropped explicitly before the columns.
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table.'_company_id_is_deleted_index');
                $t->dropUnique($table.'_company_id_uuid_unique');
            });
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['uuid', 'client_created_at', 'client_updated_at', 'version', 'is_deleted', 'entered_by']);
            });
        }

        Schema::table('poultry_feed_stock', function (Blueprint $t) {
            $t->dropColumn('ref_uuid');
        });
    }
};

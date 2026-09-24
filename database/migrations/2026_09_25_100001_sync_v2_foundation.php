<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — offline foundation (plan §B3, Appendix B.0/B.1, P1-1/P1-2/P1-5).
 * Additive and guarded: sync columns + server_seq on every shop/budget/poultry
 * table with backfill, the global sequence, devices, batch log and conflicts.
 */
return new class extends Migration
{
    /** Tenant tables that gain the standard sync columns. */
    private const TENANT_TABLES = [
        'stock_categories', 'stock_sub_categories', 'stock_items', 'stock_records',
        'sale_records', 'sale_record_items', 'payments',
        'financial_categories', 'financial_periods', 'financial_records',
        'budget_programs', 'budget_item_categories', 'budget_items', 'contribution_records',
    ];

    private const POULTRY_TENANT_TABLES = [
        'poultry_batches', 'poultry_feed_types', 'poultry_customers', 'poultry_daily_records', 'poultry_feed_stock',
        'poultry_sales', 'poultry_expenses', 'poultry_egg_transactions', 'poultry_mortality_events',
        'poultry_health_events', 'poultry_vaccination_events',
    ];

    private const POULTRY_REFERENCE_TABLES = ['poultry_farm_types', 'poultry_production_guide_tasks'];

    public function up(): void
    {
        if (! Schema::hasTable('sync_sequence')) {
            Schema::create('sync_sequence', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('last_seq')->default(0);
            });
            DB::table('sync_sequence')->insert(['id' => 1, 'last_seq' => 0]);
        }

        if (! Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->char('device_id', 36);
                $table->string('name', 100)->nullable();
                $table->string('platform', 20)->nullable();
                $table->string('app_version', 30)->nullable();
                $table->string('number_prefix', 6);
                $table->unsignedInteger('prefix_index');
                $table->string('status', 20)->default('active'); // active | revoked
                $table->timestamp('last_seen_at')->nullable();
                $table->unsignedBigInteger('last_pull_seq')->default(0);
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'device_id'], 'devices_company_device_unique');
                $table->unique(['company_id', 'number_prefix'], 'devices_company_prefix_unique');
                $table->index(['device_id'], 'devices_device_id_idx');
            });
        }

        if (! Schema::hasTable('sync_batches')) {
            Schema::create('sync_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->char('device_id', 36)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->char('batch_uuid', 36);
                $table->string('kind', 30)->default('generic');
                $table->string('status', 20); // applied | replayed | rejected | conflict | held
                $table->unsignedInteger('ops_count')->default(0);
                $table->json('payload')->nullable(); // kept only for held batches
                $table->json('result')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'batch_uuid'], 'sync_batches_company_batch_unique');
                $table->index(['company_id', 'status'], 'sync_batches_company_status_idx');
            });
        }

        if (! Schema::hasTable('sync_conflicts')) {
            Schema::create('sync_conflicts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->char('device_id', 36)->nullable();
                $table->string('table_name', 60);
                $table->char('row_uuid', 36)->nullable();
                $table->string('code', 40); // stale_version | stock_exception | delete_vs_edit | duplicate | ...
                $table->string('title', 191)->nullable();
                $table->json('local_json')->nullable();
                $table->json('server_json')->nullable();
                $table->string('state', 20)->default('open'); // open | resolved | expired
                $table->string('resolution', 20)->nullable(); // mine | server | merged | adjust_stock | counted | ignore
                $table->unsignedBigInteger('resolved_by_id')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'state'], 'sync_conflicts_company_state_idx');
                $table->index(['company_id', 'table_name', 'row_uuid'], 'sync_conflicts_row_idx');
            });
        }

        foreach (self::TENANT_TABLES as $table) {
            $this->addSyncColumns($table);
        }
        foreach (self::POULTRY_TENANT_TABLES as $table) {
            $this->addSeqColumns($table);
        }
        foreach (self::POULTRY_REFERENCE_TABLES as $table) {
            $this->addSeqColumns($table);
        }

        // Sales: provisional (device) numbers and the offline stock-exception flag.
        Schema::table('sale_records', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_records', 'provisional_number')) {
                $table->string('provisional_number', 40)->nullable()->after('invoice_number');
            }
            if (! Schema::hasColumn('sale_records', 'stock_exception')) {
                $table->boolean('stock_exception')->default(false)->after('provisional_number');
            }
        });
        $this->addUniqueIfMissing('sale_records', ['company_id', 'device_id', 'provisional_number'], 'sale_records_company_device_provisional_unique');

        // Companies: offline policies (Appendix B.2 subset needed by the sync engine).
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'negative_stock_policy')) {
                $table->string('negative_stock_policy', 10)->default('flag')->after('currency'); // allow | block | flag
            }
            if (! Schema::hasColumn('companies', 'timezone')) {
                $table->string('timezone', 40)->nullable()->after('negative_stock_policy');
            }
            if (! Schema::hasColumn('companies', 'is_demo')) {
                $table->boolean('is_demo')->default(false)->after('timezone');
            }
        });

        // Backfill: uuid for every row, then an ascending server_seq fill from the global counter.
        foreach (array_merge(self::TENANT_TABLES, self::POULTRY_TENANT_TABLES, self::POULTRY_REFERENCE_TABLES) as $table) {
            if (Schema::hasColumn($table, 'uuid')) {
                DB::statement("UPDATE `{$table}` SET uuid = UUID() WHERE uuid IS NULL OR uuid = ''");
            }
            if (Schema::hasColumn($table, 'client_uuid')) {
                DB::statement("UPDATE `{$table}` SET client_uuid = uuid WHERE client_uuid IS NULL");
            }
        }
        foreach (array_merge(self::TENANT_TABLES, self::POULTRY_TENANT_TABLES, self::POULTRY_REFERENCE_TABLES) as $table) {
            DB::statement('SET @seq := (SELECT last_seq FROM sync_sequence WHERE id = 1)');
            DB::statement("UPDATE `{$table}` SET server_seq = (@seq := @seq + 1) WHERE server_seq = 0 ORDER BY id");
            DB::statement('UPDATE sync_sequence SET last_seq = @seq WHERE id = 1');
        }
        foreach (self::TENANT_TABLES as $table) {
            $this->addUniqueIfMissing($table, ['company_id', 'uuid'], "{$table}_company_uuid_unique");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_batches');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('sync_sequence');
        // Column additions stay (additive migration, plan B.3).
    }

    private function addSyncColumns(string $table): void
    {
        Schema::table($table, function (Blueprint $t) use ($table) {
            if (! Schema::hasColumn($table, 'uuid')) {
                $t->char('uuid', 36)->nullable()->after('id');
            }
            if (! Schema::hasColumn($table, 'company_id')) {
                $t->unsignedBigInteger('company_id')->nullable()->after('uuid');
            }
            if (! Schema::hasColumn($table, 'server_seq')) {
                $t->unsignedBigInteger('server_seq')->default(0);
            }
            if (! Schema::hasColumn($table, 'client_created_at')) {
                $t->unsignedBigInteger('client_created_at')->nullable();
            }
            if (! Schema::hasColumn($table, 'client_updated_at')) {
                $t->unsignedBigInteger('client_updated_at')->nullable();
            }
            if (! Schema::hasColumn($table, 'version')) {
                $t->unsignedInteger('version')->default(1);
            }
            if (! Schema::hasColumn($table, 'is_deleted')) {
                $t->boolean('is_deleted')->default(false);
            }
            if (! Schema::hasColumn($table, 'created_by_uuid')) {
                $t->char('created_by_uuid', 36)->nullable();
            }
            if (! Schema::hasColumn($table, 'device_id')) {
                $t->char('device_id', 36)->nullable();
            }
        });
        $this->addIndexIfMissing($table, ['company_id', 'server_seq'], "{$table}_company_seq_idx");
        $this->addIndexIfMissing($table, ['company_id', 'is_deleted'], "{$table}_company_deleted_idx");
    }

    private function addSeqColumns(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($table) {
            if (! Schema::hasColumn($table, 'server_seq')) {
                $t->unsignedBigInteger('server_seq')->default(0);
            }
            if (! Schema::hasColumn($table, 'device_id')) {
                $t->char('device_id', 36)->nullable();
            }
        });
        $cols = Schema::hasColumn($table, 'company_id') ? ['company_id', 'server_seq'] : ['server_seq'];
        $this->addIndexIfMissing($table, $cols, "{$table}_seq_idx");
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $index]);

        return $row && (int) $row->c > 0;
    }

    private function addIndexIfMissing(string $table, array $columns, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`".implode('`,`', $columns).'`)');
        }
    }

    private function addUniqueIfMissing(string $table, array $columns, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$index}` (`".implode('`,`', $columns).'`)');
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * admin_users.username is NULLABLE on production and every older database (changed by hand long ago:
 * phone-only and member sign-ups have no username), but the 2016 laravel-admin migration creates it
 * NOT NULL, so a freshly migrated database refused those users. Bring fresh databases in line.
 * Only the NULL flag changes; the column's current length and its unique index are kept. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setNullable(true);
    }

    public function down(): void
    {
        // Intentionally a no-op: production has always been NULLABLE and may hold NULL usernames.
    }

    private function setNullable(bool $nullable): void
    {
        $table = (string) config('admin.database.users_table', 'admin_users');
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'username')) {
            return;
        }
        $column = DB::selectOne('SELECT IS_NULLABLE AS nullable, COLUMN_TYPE AS type, COLLATION_NAME AS collation FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, 'username']);
        if ($column === null || ($column->nullable === 'YES') === $nullable) {
            return;
        }
        $collate = $column->collation ? ' COLLATE '.$column->collation : '';
        DB::statement("ALTER TABLE `{$table}` MODIFY `username` {$column->type}{$collate} ".($nullable ? 'NULL DEFAULT NULL' : 'NOT NULL'));
    }
};

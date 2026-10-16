<?php

namespace App\Services\Install;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * The database's own lock on "one business per installation" (docs/STANDALONE_EDITION_PLAN.md §6,
 * layer 3). Company::booted() refuses a second company and the unique `standalone_slot` backs it,
 * but raw SQL skips the model, so the installer adds triggers after migrating:
 *
 *  - BEFORE INSERT / BEFORE UPDATE force `standalone_slot = 1`, so a second row hits the unique index;
 *  - BEFORE DELETE refuses outright (SIGNAL SQLSTATE '45000'): the one business is never deleted.
 *
 * Idempotent (looks in information_schema.TRIGGERS first). The backup (DatabaseBackup) dumps the
 * triggers with the data, so a restore keeps the lock. Never installed on the SaaS.
 *
 * Note: CREATE / DROP TRIGGER are DDL, so on MySQL they end any open transaction.
 */
final class SingleBusinessGuard
{
    public const TABLE = 'companies';

    /** name => [timing, event, MySQL body] */
    public const TRIGGERS = [
        'companies_one_business_insert' => ['BEFORE', 'INSERT', 'SET NEW.standalone_slot = 1'],
        'companies_one_business_update' => ['BEFORE', 'UPDATE', 'SET NEW.standalone_slot = 1'],
        'companies_one_business_delete' => ['BEFORE', 'DELETE', "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".Company::ONE_BUSINESS."'"],
    ];

    /** Create the triggers that are missing. @return list<string> the ones created now */
    public function install(): array
    {
        $created = [];
        $existing = $this->existing();
        foreach (self::TRIGGERS as $name => [$timing, $event, $body]) {
            if (in_array($name, $existing, true)) {
                continue;
            }
            DB::unprepared($this->isSqlite() ? $this->sqliteTrigger($name, $event) : sprintf(
                'CREATE TRIGGER `%s` %s %s ON `%s` FOR EACH ROW %s', $name, $timing, $event, self::TABLE, $body
            ));
            $created[] = $name;
        }

        return $created;
    }

    /** Remove the triggers (tests, and a copy being turned back into a SaaS database by hand). */
    public function uninstall(): void
    {
        foreach (array_keys(self::TRIGGERS) as $name) {
            DB::unprepared($this->isSqlite() ? "DROP TRIGGER IF EXISTS \"{$name}\"" : "DROP TRIGGER IF EXISTS `{$name}`");
        }
    }

    /** Whether every trigger is in place (the health check). */
    public function installed(): bool
    {
        return array_diff(array_keys(self::TRIGGERS), $this->existing()) === [];
    }

    /** @return list<string> the guard's triggers that exist now */
    public function existing(): array
    {
        $rows = $this->isSqlite()
            ? DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [self::TABLE])
            : DB::select('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', [self::TABLE]);

        return array_values(array_intersect(array_keys(self::TRIGGERS), array_map(fn ($r) => (string) $r->name, $rows)));
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /** SQLite cannot assign NEW in a BEFORE trigger: insert/update are fixed up after, the unique index still refuses a second row. */
    private function sqliteTrigger(string $name, string $event): string
    {
        return match ($event) {
            'DELETE' => "CREATE TRIGGER \"{$name}\" BEFORE DELETE ON \"".self::TABLE."\" BEGIN SELECT RAISE(ABORT, '".Company::ONE_BUSINESS."'); END",
            default => "CREATE TRIGGER \"{$name}\" AFTER {$event} ON \"".self::TABLE.'" WHEN NEW.standalone_slot IS NOT 1 BEGIN UPDATE "'
                .self::TABLE.'" SET standalone_slot = 1 WHERE rowid = NEW.rowid; END',
        };
    }
}

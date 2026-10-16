<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * A standalone installation's MySQL database, saved to one gzip file and put back from it
 * (docs/STANDALONE_EDITION_PLAN.md §12.4).
 *
 * Written in PHP rather than shelling out to mysqldump: buyers' cPanel hosts do not always allow
 * it (or proc_open), and a backup that only works on some servers is the one that is missing on
 * the day it is needed. Table by table: DROP + CREATE, the rows in INSERTs of up to 500, then the
 * triggers (they keep the copy to one business). Every statement ends in ";" and a separator
 * comment line, and triggers are wrapped in DELIMITER, so the file also restores with
 * `gunzip < database.sql.gz | mysql …` or phpMyAdmin's Import.
 */
class DatabaseBackup
{
    private const SEPARATOR = "\n-- bp:statement\n";

    private const BATCH = 500;

    /** An INSERT is cut short of 500 rows once its values pass this, to stay under max_allowed_packet. */
    private const BATCH_BYTES = 1 << 20;

    /** Written as X'hex' so no byte is ever reinterpreted as text. */
    private const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection'];

    public function __construct(private readonly ?string $connection = null) {}

    /** Write the whole database to `$path` (".sql.gz" is added when missing). Returns the file. */
    public function dump(string $path): string
    {
        $pdo = $this->pdo();
        $path = str_ends_with($path, '.sql.gz') ? $path : $path.'.sql.gz';
        File::ensureDirectoryExists(dirname($path), 0700);
        $gz = gzopen($path.'.tmp', 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('The backup file could not be created.');
        }

        $buffered = $pdo->getAttribute(self::buffered());
        try {
            $write = function (string $sql) use ($gz): void {
                if (gzwrite($gz, rtrim($sql, "; \n").';'.self::SEPARATOR) === false) {
                    throw new \RuntimeException('The backup file could not be written.');
                }
            };
            gzwrite($gz, '-- Budget Pro database backup, '.gmdate('c')."\n");
            $write('SET NAMES utf8mb4');
            $write('SET FOREIGN_KEY_CHECKS=0');
            $write("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");

            foreach ($this->tables($pdo) as $table) {
                $quoted = $this->quoteName($table);
                $write("DROP TABLE IF EXISTS {$quoted}");
                $write((string) $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(\PDO::FETCH_NUM)[1]);

                // Generated columns are left out (MySQL computes them; INSERT refuses them); binary
                // ones are written as hex. One streamed pass: no OFFSET paging, so big tables stay linear.
                $binary = $this->columns($pdo, $table);
                $columns = implode(', ', array_map(fn ($c) => $this->quoteName((string) $c), array_keys($binary)));
                $binary = array_values($binary);
                $pdo->setAttribute(self::buffered(), false);
                $rows = $pdo->query("SELECT {$columns} FROM {$quoted}");
                $values = [];
                $bytes = 0;
                while (($row = $rows->fetch(\PDO::FETCH_NUM)) !== false) {
                    foreach ($row as $i => $v) {
                        $row[$i] = match (true) {
                            $v === null => 'NULL',
                            $binary[$i] => "X'".bin2hex((string) $v)."'",
                            default => $pdo->quote((string) $v),
                        };
                    }
                    $value = '('.implode(', ', $row).')';
                    $values[] = $value;
                    $bytes += strlen($value);
                    if (count($values) === self::BATCH || $bytes >= self::BATCH_BYTES) {
                        $write("INSERT INTO {$quoted} ({$columns}) VALUES ".implode(', ', $values));
                        [$values, $bytes] = [[], 0];
                    }
                }
                $rows->closeCursor();
                $pdo->setAttribute(self::buffered(), $buffered);
                if ($values !== []) {
                    $write("INSERT INTO {$quoted} ({$columns}) VALUES ".implode(', ', $values));
                }
            }

            foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(\PDO::FETCH_ASSOC) as $trigger) {
                $name = $this->quoteName((string) $trigger['Trigger']);
                $write("DROP TRIGGER IF EXISTS {$name}");
                // Written as is: $write would trim the closing "DELIMITER ;" into "DELIMITER;".
                gzwrite($gz, sprintf(
                    "DELIMITER ;;\nCREATE TRIGGER %s %s %s ON %s FOR EACH ROW %s;;\nDELIMITER ;".self::SEPARATOR,
                    $name, $trigger['Timing'], $trigger['Event'], $this->quoteName((string) $trigger['Table']),
                    rtrim(trim((string) $trigger['Statement']), ';'),
                ));
            }

            $write('SET FOREIGN_KEY_CHECKS=1');
        } finally {
            $pdo->setAttribute(self::buffered(), $buffered);
            gzclose($gz);
        }

        rename($path.'.tmp', $path);
        chmod($path, 0600);

        return $path;
    }

    /** Put the database back exactly as `$path` holds it: every current table is dropped first. */
    public function restore(string $path): void
    {
        if (! is_file($path)) {
            throw new \RuntimeException('The backup file is missing: '.basename($path));
        }
        $pdo = $this->pdo();
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('The backup file cannot be read.');
        }

        $mode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        try {
            // Tables created since the backup must go too, or a restore after a migration leaves them behind.
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($this->tables($pdo) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS '.$this->quoteName($table));
            }

            $buffer = '';
            while (! gzeof($gz)) {
                $chunk = gzread($gz, 1 << 20);
                if ($chunk === false) {
                    throw new \RuntimeException('The backup file is damaged.');
                }
                $buffer .= $chunk;
                while (($at = strpos($buffer, self::SEPARATOR)) !== false) {
                    $this->execute($pdo, substr($buffer, 0, $at));
                    $buffer = substr($buffer, $at + strlen(self::SEPARATOR));
                }
            }
            $this->execute($pdo, $buffer);
        } finally {
            gzclose($gz);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $pdo->exec('SET SESSION sql_mode = '.$pdo->quote((string) $mode));
        }
    }

    private function execute(\PDO $pdo, string $statement): void
    {
        // Drop leading comment lines and the client-only DELIMITER lines; keep the statement.
        $statement = (string) preg_replace('/^(--[^\n]*\n)+/', '', ltrim($statement)."\n");
        $statement = (string) preg_replace('/^DELIMITER [^\n]*$/m', '', $statement);
        $statement = rtrim(trim($statement), ';');
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }

    /** @return list<string> base tables only (views are not data) */
    private function tables(\PDO $pdo): array
    {
        return array_map(
            fn (array $row) => (string) $row[0],
            $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM),
        );
    }

    /** @return array<string,bool> the stored (non-generated) columns in order => whether binary */
    private function columns(\PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$table]);
        $columns = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as [$name, $type, $extra]) {
            if (preg_match('/\b(VIRTUAL|STORED) GENERATED\b/i', (string) $extra)) {
                continue; // not DEFAULT_GENERATED, which is an ordinary column with a default
            }
            $columns[(string) $name] = in_array(strtolower((string) $type), self::BINARY_TYPES, true);
        }

        return $columns;
    }

    private function pdo(): \PDO
    {
        $connection = DB::connection($this->connection);
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Backups support MySQL and MariaDB databases.');
        }

        return $connection->getPdo();
    }

    /** PDO\\Mysql::ATTR_USE_BUFFERED_QUERY on PHP 8.4+, where the PDO:: name is deprecated (same value). */
    private static function buffered(): int
    {
        return defined("Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY") ? constant("Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY") : \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    }

    private function quoteName(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}

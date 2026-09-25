<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Nightly backups and restore drills (plan Part D, P4-6). The database password
 * goes through a temporary --defaults-extra-file, never on the command line.
 */
class BackupService
{
    private function credentialsFile(?string $database = null): string
    {
        $c = config('database.connections.'.config('database.default'));
        $file = tempnam(sys_get_temp_dir(), 'mycnf');
        $lines = ['[client]', 'user='.$c['username'], 'password="'.str_replace('"', '\"', (string) $c['password']).'"'];
        if (! empty($c['unix_socket'])) {
            $lines[] = 'socket='.$c['unix_socket'];
        } else {
            $lines[] = 'host='.$c['host'];
            $lines[] = 'port='.$c['port'];
        }
        file_put_contents($file, implode("\n", $lines)."\n");
        chmod($file, 0600);

        return $file;
    }

    public function run(): array
    {
        $db = (string) DB::getDatabaseName();
        $dir = rtrim((string) config('backup.path'), '/');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $file = "{$dir}/{$db}-".now()->format('Ymd-His').'.sql.gz';
        $cnf = $this->credentialsFile();
        try {
            $cmd = escapeshellarg((string) config('backup.mysqldump')).' --defaults-extra-file='.escapeshellarg($cnf).' --single-transaction --quick --no-tablespaces --routines '
                .escapeshellarg($db).' | gzip -c > '.escapeshellarg($file);
            $p = new Process(['bash', '-o', 'pipefail', '-c', $cmd], null, null, null, 3600);
            $p->run();
            $ok = $p->isSuccessful() && is_file($file) && filesize($file) > 0 && $this->gzipReadable($file);
            $details = ['tables' => $ok ? $this->countCreateTables($file) : 0, 'stderr' => mb_substr($p->getErrorOutput(), 0, 500)];
        } finally {
            @unlink($cnf);
        }
        $this->record('backup', $ok ? 'ok' : 'failed', $file, $ok ? (int) filesize($file) : null, $details);
        if (! $ok) {
            @unlink($file);
        }
        $this->rotate($dir, $db);

        return ['ok' => $ok, 'file' => $ok ? $file : null] + $details;
    }

    public function latest(): ?string
    {
        $files = glob(rtrim((string) config('backup.path'), '/').'/'.DB::getDatabaseName().'-*.sql.gz') ?: [];
        sort($files);

        return $files ? end($files) : null;
    }

    /**
     * Restore drill: load the latest backup into the scratch database and compare row counts,
     * or — without a scratch database — prove the dump holds every table and its rows.
     */
    public function drill(): array
    {
        $file = $this->latest();
        if ($file === null) {
            $this->record('drill', 'failed', null, null, ['reason' => 'no backup file']);

            return ['ok' => false, 'reason' => 'no backup file'];
        }
        $live = collect(DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = "BASE TABLE"'))->pluck('t')->all();
        $scratch = config('backup.drill_database');
        if ($scratch) {
            $cnf = $this->credentialsFile();
            try {
                $p = new Process(['bash', '-o', 'pipefail', '-c', 'gunzip -c '.escapeshellarg($file).' | '.escapeshellarg((string) config('backup.mysql')).' --defaults-extra-file='.escapeshellarg($cnf).' '.escapeshellarg((string) $scratch)], null, null, null, 3600);
                $p->run();
            } finally {
                @unlink($cnf);
            }
            $restored = $p->isSuccessful() ? collect(DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?', [$scratch]))->pluck('t')->all() : [];
            $missing = array_values(array_diff($live, $restored));
            $short = [];
            foreach (['companies', 'admin_users', 'stock_items', 'sale_records', 'payments', 'financial_records'] as $t) {
                if (in_array($t, $live, true) && in_array($t, $restored, true)) {
                    $a = (int) DB::table($t)->count();
                    $b = (int) DB::table("{$scratch}.{$t}")->count();
                    if ($b === 0 && $a > 0) {
                        $short[$t] = [$a, $b];
                    }
                }
            }
            $ok = $p->isSuccessful() && $missing === [] && $short === [];
            $details = ['mode' => 'restore', 'missing_tables' => $missing, 'empty_after_restore' => $short, 'stderr' => mb_substr($p->getErrorOutput(), 0, 500)];
        } else {
            $sql = $this->dumpTables($file);
            $missing = array_values(array_diff($live, $sql['tables']));
            $noRows = [];
            $taken = \Illuminate\Support\Carbon::createFromTimestamp((int) filemtime($file));
            foreach ($live as $t) {
                if (in_array($t, $sql['with_rows'], true)) {
                    continue;
                }
                // Only rows that existed when the backup was taken must be in it.
                $q = DB::table($t);
                if (\Illuminate\Support\Facades\Schema::hasColumn($t, 'created_at')) {
                    $q->where('created_at', '<', $taken);
                }
                if ($q->exists()) {
                    $noRows[] = $t;
                }
            }
            $ok = $missing === [] && $noRows === [];
            $details = ['mode' => 'file', 'tables' => count($sql['tables']), 'missing_tables' => $missing, 'tables_without_rows' => array_slice($noRows, 0, 20)];
        }
        $this->record('drill', $ok ? 'ok' : 'failed', $file, (int) filesize($file), $details);

        return ['ok' => $ok, 'file' => $file] + $details;
    }

    private function gzipReadable(string $file): bool
    {
        $h = gzopen($file, 'rb');
        if ($h === false) {
            return false;
        }
        while (! gzeof($h)) {
            if (gzread($h, 1 << 20) === false) {
                gzclose($h);

                return false;
            }
        }
        gzclose($h);

        return true;
    }

    private function countCreateTables(string $file): int
    {
        return count($this->dumpTables($file)['tables']);
    }

    /** @return array{tables: array<int, string>, with_rows: array<int, string>} */
    public function dumpTables(string $file): array
    {
        $tables = [];
        $rows = [];
        $h = gzopen($file, 'rb');
        while ($h && ($line = gzgets($h, 1 << 16)) !== false) {
            if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
                $tables[] = $m[1];
            } elseif (preg_match('/^INSERT INTO `([^`]+)`/', $line, $m)) {
                $rows[$m[1]] = true;
            }
        }
        if ($h) {
            gzclose($h);
        }

        return ['tables' => $tables, 'with_rows' => array_keys($rows)];
    }

    private function rotate(string $dir, string $db): void
    {
        $cut = now()->subDays(max(1, (int) config('backup.keep_days')))->getTimestamp();
        foreach (glob("{$dir}/{$db}-*.sql.gz") ?: [] as $f) {
            if (filemtime($f) < $cut) {
                @unlink($f);
            }
        }
    }

    private function record(string $kind, string $status, ?string $file, ?int $bytes, array $details): void
    {
        DB::table('backup_runs')->insert(['kind' => $kind, 'status' => $status, 'file' => $file ? basename($file) : null, 'bytes' => $bytes, 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
        if ($status !== 'ok') {
            \Illuminate\Support\Facades\Log::error("[backup] {$kind} failed", $details);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Support\SchemaIntegrity;
use Illuminate\Console\Command;

/** Report (or with --apply, add) foreign keys, uniques and status checks the data allows (plan Part D, P4-5). */
class SchemaIntegrityCommand extends Command
{
    protected $signature = 'schema:integrity {--apply : Add every constraint the data already satisfies}';

    protected $description = 'Check foreign keys, per-company uniques and status values; list what blocks a constraint';

    public function handle(): int
    {
        $rows = SchemaIntegrity::run((bool) $this->option('apply'));
        $this->table(['Kind', 'Target', 'Status', 'Detail'], array_map(fn ($r) => [$r['kind'], $r['target'], $r['status'], $r['detail'] ?? ''], $rows));
        $skipped = count(array_filter($rows, fn ($r) => $r['status'] === 'skipped'));
        $skipped ? $this->warn("{$skipped} constraint(s) need data cleaning first.") : $this->info('All constraints in place or ready.');

        return self::SUCCESS;
    }
}

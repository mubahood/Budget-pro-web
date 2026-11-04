<?php

namespace App\Console\Commands;

use App\Services\Rentals\Search\SearchIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Estate Ledger's search index, for every account or one: fills what is missing and repairs anything a crash missed (safe to run at
 * any time, and on every deploy). `--full` rewrites everything, after the rules for cutting text change.
 */
class RentalsSearchIndex extends Command
{
    protected $signature = 'rentals:search-index {--company= : One account only} {--full : Rewrite every row}';

    protected $description = 'Estate Ledger: fill and repair the search index';

    public function handle(SearchIndex $index): int
    {
        $companies = DB::table('rental_settings')->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))->pluck('company_id');
        $indexed = 0;
        $removed = 0;
        foreach ($companies as $id) {
            $done = $index->fill((int) $id, (bool) $this->option('full'));
            $indexed += $done['indexed'];
            $removed += $done['removed'];
        }
        $this->info("Search index: {$companies->count()} accounts, {$indexed} rows written, {$removed} removed.");

        return self::SUCCESS;
    }
}

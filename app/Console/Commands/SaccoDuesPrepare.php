<?php

namespace App\Console\Commands;

use App\Services\Sacco\Dues\DuesService;
use Illuminate\Console\Command;

/** Make every contribution's missing lines up to today (one per person per period). Running it again changes nothing. */
class SaccoDuesPrepare extends Command
{
    protected $signature = 'sacco:dues-prepare {--company= : one company only}';

    protected $description = 'Make the missing dues lines (one per person per period) for every active contribution';

    public function handle(DuesService $dues): int
    {
        $made = $dues->prepare($this->option('company') !== null ? (int) $this->option('company') : null);
        $this->info("Dues lines made: {$made}.");

        return self::SUCCESS;
    }
}

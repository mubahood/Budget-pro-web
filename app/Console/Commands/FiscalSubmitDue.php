<?php

namespace App\Console\Commands;

use App\Services\Fiscal\FiscalService;
use Illuminate\Console\Command;

/**
 * Sends the fiscal receipts that are due (supermarket plan F2): new sales and retries whose backoff has passed.
 * Rows exist only for shops with the `fiscal` feature on and a connector configured, so for everyone else this
 * is one indexed query that finds nothing.
 */
class FiscalSubmitDue extends Command
{
    protected $signature = 'fiscal:submit-due {--limit=100 : Most submissions per run}';

    protected $description = 'Send due fiscal receipts / e-invoices to the tax authority, with retries';

    public function handle(FiscalService $fiscal): int
    {
        $r = $fiscal->processDue(max(1, (int) $this->option('limit')));
        $this->info("Fiscal: {$r['sent']} sent, {$r['waiting']} will retry, {$r['failed']} failed.");

        return self::SUCCESS;
    }
}

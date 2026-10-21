<?php

namespace App\Console\Commands;

use App\Services\Sacco\Payments\SaccoCollections;
use Illuminate\Console\Command;

/**
 * Every minute (04 §11.1, P3.1): ask the provider about SACCO members' mobile-money requests still pending, so a
 * payment whose webhook never came is still recorded (once); one unanswered for a day expires.
 */
class SaccoCollectionsPoll extends Command
{
    protected $signature = 'sacco:collections-poll {--company=}';

    protected $description = 'Ask the mobile-money provider about SACCO collections still pending';

    public function handle(SaccoCollections $collections): int
    {
        $this->info($collections->poll($this->option('company') ? (int) $this->option('company') : null).' collection(s) settled, declined or expired.');

        return self::SUCCESS;
    }
}

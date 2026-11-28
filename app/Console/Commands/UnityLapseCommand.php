<?php

namespace App\Console\Commands;

use App\Services\Unity\StandingJobs;
use Illuminate\Console\Command;

/** Daily (UnityDesk doc 12): see StandingJobs::lapse(). */
class UnityLapseCommand extends Command
{
    protected $signature = 'unity:lapse {--company= : one association only}';

    protected $description = 'Mark members whose membership has ended as lapsed and tell each once';

    public function handle(): int
    {
        $only = $this->option('company');
        foreach (StandingJobs::associations($only === null ? null : (int) $only) as $company) {
            $r = StandingJobs::lapse($company);
            $this->info('Association #'.$company->id.': '.json_encode($r));
        }

        return self::SUCCESS;
    }
}

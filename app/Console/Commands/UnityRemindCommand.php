<?php

namespace App\Console\Commands;

use App\Services\Unity\StandingJobs;
use Illuminate\Console\Command;

/** Daily (UnityDesk doc 12): see StandingJobs::remind(). */
class UnityRemindCommand extends Command
{
    protected $signature = 'unity:remind {--company= : one association only}';

    protected $description = 'Tell members whose membership ends within a week, once per period';

    public function handle(): int
    {
        $only = $this->option('company');
        foreach (StandingJobs::associations($only === null ? null : (int) $only) as $company) {
            $r = StandingJobs::remind($company);
            $this->info('Association #'.$company->id.': '.json_encode($r));
        }

        return self::SUCCESS;
    }
}

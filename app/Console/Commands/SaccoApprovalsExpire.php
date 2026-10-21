<?php

namespace App\Console\Commands;

use App\Services\Sacco\SaccoApprovals;
use Illuminate\Console\Command;

/** Hourly (04 §7): SACCO requests left undecided for 7 days expire; an expired request never executes. */
class SaccoApprovalsExpire extends Command
{
    protected $signature = 'sacco:approvals-expire';

    protected $description = 'Expire SACCO maker-checker requests older than 7 days';

    public function handle(SaccoApprovals $approvals): int
    {
        $this->info($approvals->expireDue().' request(s) expired.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Services\Sacco\Approvals;

use App\Models\SaccoApproval;

/**
 * An executor whose subject waits on the request (a withdrawal in `pending_approval`): told when the request is
 * rejected, cancelled or expires, so the subject never waits for a decision that will not come.
 */
interface ClosesSubject
{
    /** @param  string  $status  rejected | cancelled | expired */
    public function closed(SaccoApproval $approval, string $status): void;
}

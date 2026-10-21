<?php

namespace App\Services\Sacco\Approvals;

use App\Models\SaccoApproval;
use App\Models\User;

/**
 * An executor whose checker depends on the request itself (a loan decision: a committee member's vote, an officer
 * within their delegated limit, the board on appeal): asked before a checker's approval or rejection is recorded.
 * Throws a BusinessRuleException to refuse that checker.
 */
interface ChecksChecker
{
    /** @param  string  $decision  approve | reject */
    public function assertCanCheck(SaccoApproval $approval, User $checker, string $decision): void;
}

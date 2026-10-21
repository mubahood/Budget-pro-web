<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\User;

/**
 * An unlicensed SACCO for the simple flows (docs/akiba/flows-plan.md): the owner, members with savings, staff in the
 * roles the flows use, and API headers for staff and members.
 */
trait MakesSimpleSacco
{
    use MakesSaccoLoans, SignsInSaccoMembers;

    /** @return array{0: Company, 1: User} */
    protected function simpleSacco(string $mode = 'office'): array
    {
        return $this->loanSacco($mode, 'Simple SACCO', false);
    }

    /** An active member with $savings in their ordinary savings (deposited in cash). */
    protected function saver(Company $company, User $by, int $savings = 0, string $first = 'Member'): SaccoMember
    {
        $m = $this->loanMember($company, $by, 400, ['first_name' => $first]);
        if ($savings > 0) {
            $this->loanSavings($company, $m, $savings, $by, \App\Models\SaccoSetting::for((int) $company->id)?->mode === 'group' ? 'GRP' : 'ORD');
        }

        return $m->fresh();
    }

    /** @return array<string, string> */
    protected function staffHeaders(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }
}

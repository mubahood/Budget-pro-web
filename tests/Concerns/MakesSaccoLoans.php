<?php

namespace Tests\Concerns;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SaccoMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\SaccoSetupService;
use Database\Seeders\AkibaPlanSeeder;

/**
 * A SACCO set up for the loan tests (P2): on the top Akiba plan, in office or group mode, with active members and
 * staff made the way the portal makes them. Use in an ApiTestCase (it registers the tenant through the API).
 */
trait MakesSaccoLoans
{
    use MakesSaccoTeam;

    /** @return array{0: Company, 1: User} the SACCO and its owner (seated as chairperson) */
    protected function loanSacco(string $mode = 'office', string $name = 'Loans SACCO'): array
    {
        if (! Plan::where('slug', 'akiba-enterprise')->exists()) {
            $this->seed(AkibaPlanSeeder::class);
        }
        $t = $this->registerTenant(['modules' => ['sacco']]);
        $company = Company::find($t['company_id']);
        Subscription::where('company_id', $company->id)->update(['plan_id' => Plan::where('slug', 'akiba-enterprise')->value('id')]);
        $owner = User::withoutGlobalScopes()->find($t['user_id']);
        app(SaccoSetupService::class)->setup($company->fresh(), ['mode' => $mode, 'legal_name' => $name.' '.uniqid()], $owner);

        return [$company->fresh(), $owner];
    }

    /** An active member who joined $daysAgo days ago. @param array<string, mixed> $over */
    protected function loanMember(Company $company, User $by, int $daysAgo = 400, array $over = []): SaccoMember
    {
        $r = app(MemberService::class)->register($company, array_merge(['first_name' => 'Member', 'last_name' => 'Test '.uniqid(), 'gender' => 'female',
            'date_of_birth' => '1988-05-01', 'phone' => '0772'.random_int(100000, 999999), 'consent_data' => true, 'consent_messages' => true,
            'joined_on' => now()->subDays($daysAgo)->toDateString()], $over), $by, ['status' => 'active']);

        return $r['member'];
    }

    protected function errorCode(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }
}

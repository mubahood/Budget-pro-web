<?php

namespace Tests\Concerns;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Sacco\Loans\LoanApplications;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\SaccoSetupService;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
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

        $member = $r['member'];
        $member->forceFill(['kyc_status' => 'verified', 'kyc_verified_at' => now()])->save();

        return $member->fresh();
    }

    /** The member's ordinary savings account (opened if need be) with $minor more in it, deposited in cash. */
    protected function loanSavings(Company $company, SaccoMember $member, int $minor, User $by, string $code = 'ORD', ?string $on = null): SaccoSavingsAccount
    {
        $product = SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
        $account = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $company->id)->where('member_id', $member->id)->where('product_id', $product->id)->first()
            ?? app(SavingsAccounts::class)->open($company, (int) $member->id, (int) $product->id, $by, $on !== null ? ['opened_on' => $on] : []);
        if ($minor > 0) {
            app(SaccoTransactions::class)->record($company, ['kind' => 'deposit', 'savings_account_id' => $account->id, 'amount_minor' => $minor, 'channel' => 'cash']
                + ($on !== null ? ['value_date' => $on] : []), $by, $on !== null ? ['entry_date' => $on] : []);
        }

        return $account->fresh();
    }

    /** A staff login in a role, seated in an office (with a member record when it must be one, and an approval limit). */
    protected function officerUser(Company $company, string $role, string $position, ?int $limitMinor = null, ?int $memberId = null): User
    {
        $user = $this->saccoStaff($company, $role);
        (new OfficerService())->appoint((int) $company->id, array_filter(['position' => $position, 'user_id' => (int) $user->id, 'member_id' => $memberId,
            'approval_limit_minor' => $limitMinor, 'starts_on' => now()->subYear()->toDateString()], fn ($v) => $v !== null), (int) $company->owner_id);

        return $user;
    }

    protected function ordinaryProduct(Company $company): SaccoLoanProduct
    {
        return SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'ORD')->firstOrFail();
    }

    /** A submitted (and, with $appraiser, appraised) application. */
    protected function application(Company $company, SaccoMember $member, int $amount, int $term, User $officer, ?User $appraiser = null, ?SaccoLoanProduct $product = null): SaccoLoan
    {
        $svc = new LoanApplications();
        $loan = $svc->create($company, ['member_id' => (int) $member->id, 'product_id' => (int) ($product ?? $this->ordinaryProduct($company))->id, 'applied_minor' => $amount,
            'term' => $term, 'purpose' => 'Stock for the shop', 'disbursement_channel' => 'savings'], $officer, ['submit' => true]);
        if ($appraiser !== null) {
            $loan = $svc->appraise($company, (int) $loan->id, $appraiser, ['monthly_income_minor' => 5_000_000, 'monthly_expenses_minor' => 1_000_000]);
        }

        return $loan;
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

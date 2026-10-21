<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\Plan;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\SaccoSetupService;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use Database\Seeders\AkibaPlanSeeder;
use Illuminate\Support\Str;

/**
 * A set-up SACCO for the kernel's savings, shares and API tests (needs ApiTestCase and MakesSaccoTeam): a registered
 * sacco account on Akiba Enterprise, set up through SaccoSetupService (so its chart, periods and default products
 * exist), an owner, a treasurer and a teller, members and savings accounts, and money in them through the services.
 */
trait MakesSaccoBooks
{
    /** @return array{company: Company, owner: User, treasurer: User, teller: User, token: string} */
    protected function saccoCompany(string $mode = 'office', string $plan = 'akiba-enterprise'): array
    {
        if (! Plan::where('slug', $plan)->exists()) {
            $this->seed(AkibaPlanSeeder::class);
        }
        $t = $this->registerTenant(['modules' => ['sacco']]);
        $company = Company::find($t['company_id']);
        Subscription::where('company_id', $company->id)->update(['plan_id' => Plan::where('slug', $plan)->value('id')]);
        $owner = User::withoutGlobalScopes()->find($t['user_id']);
        app(SaccoSetupService::class)->setup($company->fresh(), ['mode' => $mode, 'legal_name' => 'Test Teachers SACCO', 'registration_no' => 'CS 1234'], $owner);
        $company = $company->fresh();

        return ['company' => $company, 'owner' => $owner, 'treasurer' => $this->saccoStaff($company, 'sacco_treasurer'), 'teller' => $this->saccoStaff($company, 'sacco_teller'),
            'token' => $t['token']];
    }

    protected function activeMember(Company $company, User $by, string $first = 'Akello', string $last = 'Grace'): SaccoMember
    {
        return app(MemberService::class)->register($company, ['first_name' => $first, 'last_name' => $last.' '.Str::random(4), 'consent_data' => true], $by, ['status' => 'active'])['member'];
    }

    protected function product(Company $company, string $code = 'ORD'): SaccoSavingsProduct
    {
        return SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    protected function savingsAccount(Company $company, SaccoMember $member, User $by, string $code = 'ORD'): SaccoSavingsAccount
    {
        return app(SavingsAccounts::class)->open($company, (int) $member->id, (int) $this->product($company, $code)->id, $by);
    }

    /** @param array<string, mixed> $extra */
    protected function deposit(Company $company, SaccoSavingsAccount $account, int $minor, User $by, array $extra = [], array $opts = []): SaccoTransaction
    {
        return app(SaccoTransactions::class)->record($company, ['kind' => 'deposit', 'savings_account_id' => $account->id, 'amount_minor' => $minor, 'channel' => 'cash'] + $extra, $by, $opts)['transaction'];
    }

    /** @param array<string, mixed> $extra */
    protected function withdraw(Company $company, SaccoSavingsAccount $account, int $minor, User $by, array $extra = []): SaccoTransaction
    {
        return app(SaccoTransactions::class)->record($company, ['kind' => 'withdrawal', 'savings_account_id' => $account->id, 'amount_minor' => $minor, 'channel' => 'cash'] + $extra, $by)['transaction'];
    }

    protected function balanceOf(int $accountId): int
    {
        return (int) SaccoSavingsAccount::withoutGlobalScopes()->whereKey($accountId)->value('balance_minor');
    }
}

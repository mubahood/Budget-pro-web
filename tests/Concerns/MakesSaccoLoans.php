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

    /** @var array<int, array{officer: User, manager: User}> per company: the loans officer and a manager with a large limit */
    protected array $loanStaff = [];

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

    /**
     * The loans officer and a manager with a large limit, made once per SACCO; with them a depositor's 200 m in cash, so
     * the SACCO has the liquidity, the assets and the reserves (a 20 m single-borrower limit) a loan test needs.
     *
     * @return array{officer: User, manager: User}
     */
    protected function loanStaffOf(Company $company): array
    {
        if (! isset($this->loanStaff[(int) $company->id])) {
            $owner = User::withoutGlobalScopes()->findOrFail($company->owner_id);
            $this->loanSavings($company, $this->loanMember($company, $owner, 1000, ['first_name' => 'Depositor']), 200_000_000, $owner);
            // A mature SACCO's reserves (institutional capital, for the single-borrower limit): 80 m in the bank against 3200.
            $cid = (int) $company->id;
            (new \App\Services\Sacco\Ledger\PostingService())->post(\App\Services\Sacco\Ledger\JournalDraft::make($cid, 'manual', 'test:reserve:'.$cid)
                ->debit(\App\Services\Sacco\Ledger\ChartOfAccounts::id($cid, 'bank.main'), \App\Support\Sacco\Amount::of(80_000_000, 'UGX'))
                ->credit(\App\Services\Sacco\Ledger\ChartOfAccounts::id($cid, 'reserve.statutory'), \App\Support\Sacco\Amount::of(80_000_000, 'UGX')));
            $this->loanStaff[(int) $company->id] = ['officer' => $this->saccoStaff($company, 'sacco_loans_officer'),
                'manager' => $this->officerUser($company, 'sacco_manager', 'manager', 1_000_000_000)];
        }

        return $this->loanStaff[(int) $company->id];
    }

    /** An approved loan (appraised by the loans officer, approved by the manager within their limit). */
    protected function approvedLoan(Company $company, SaccoMember $member, int $amount, int $term, ?SaccoLoanProduct $product = null): SaccoLoan
    {
        $staff = $this->loanStaffOf($company);
        $loan = $this->application($company, $member, $amount, $term, $staff['officer'], $staff['officer'], $product);
        (new \App\Services\Sacco\Loans\Committee())->requestDelegated($company, (int) $loan->id, $staff['officer']);

        return (new \App\Services\Sacco\Loans\Committee())->decide($company, (int) $loan->id, $staff['manager'], 'approve');
    }

    /** An approved loan with its contract printed and signed before a witness on $signedOn (today by default). */
    protected function signedLoan(Company $company, SaccoMember $member, int $amount, int $term, ?string $signedOn = null, ?SaccoLoanProduct $product = null): SaccoLoan
    {
        $loan = $this->approvedLoan($company, $member, $amount, $term, $product);
        $officer = $this->loanStaffOf($company)['officer'];
        (new \App\Services\Sacco\Loans\Contracts())->generate($company, (int) $loan->id, $officer);

        return (new \App\Services\Sacco\Loans\Contracts())->sign($company, (int) $loan->id, $officer, ['witness_name' => 'Witness '.uniqid(), 'signed_on' => $signedOn]);
    }

    /** A signed loan paid out (requested by the treasurer, approved by the manager). @param array<string, mixed> $data */
    protected function activeLoan(Company $company, SaccoMember $member, int $amount, int $term, array $data = ['channel' => 'savings'], ?string $on = null, ?SaccoLoanProduct $product = null): SaccoLoan
    {
        $loan = $this->signedLoan($company, $member, $amount, $term, $on, $product);
        $staff = $this->loanStaffOf($company);
        $treasurer = $this->loanTreasurer($company);
        $approval = (new \App\Services\Sacco\Loans\Disbursement())->request($company, (int) $loan->id, $treasurer, $data + ($on !== null ? ['disburse_on' => $on] : []));
        (new \App\Services\Sacco\SaccoApprovals())->approve($company, (int) $approval->id, $staff['manager']);

        return $loan->fresh();
    }

    /** @var array<int, User> */
    protected array $treasurers = [];

    protected function loanTreasurer(Company $company): User
    {
        return $this->treasurers[(int) $company->id] ??= $this->saccoStaff($company, 'sacco_treasurer');
    }
}

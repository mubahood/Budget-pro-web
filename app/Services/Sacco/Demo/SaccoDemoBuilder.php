<?php

namespace App\Services\Sacco\Demo;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Plan;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoMember;
use App\Models\SaccoPeriod;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Onboarding\OnboardingEvents;
use App\Services\Onboarding\OnboardingService;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\OpeningBalances;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Loans\Committee;
use App\Services\Sacco\Loans\Contracts;
use App\Services\Sacco\Loans\Disbursement;
use App\Services\Sacco\Loans\GuarantorRecovery;
use App\Services\Sacco\Loans\Guarantors;
use App\Services\Sacco\Loans\LoanApplications;
use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Loans\LoanProducts;
use App\Services\Sacco\Loans\LoansModule;
use App\Services\Sacco\Loans\MemberFunds;
use App\Services\Sacco\Loans\Repayments;
use App\Services\Sacco\Loans\Restructure;
use App\Services\Sacco\Loans\WriteOffs;
use App\Services\Sacco\Members\MemberLogin;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoSetupService;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use App\Services\Sacco\Shares\ShareService;
use App\Services\Team\TeamService;
use App\Support\Rules\SaccoLoanProductRules;
use Database\Seeders\AkibaPlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fills the Akiba Ledger demo (SaccoDemo, AK-35): "Wakiso Teachers' SACCO (Demo)", office mode, 60 fictional teachers
 * and `demo.sacco.months` of books, every shilling posted through the SACCO's own services (no row is written into a
 * ledger table by hand), so the trial balance, the sub-ledgers and LedgerVerifier agree.
 *
 * The clock runs forward day by day from the first of the month `months` ago: the SACCO moves its books in with
 * opening balances on day one, then each day's work happens at its own time (payroll check-off, counter deposits,
 * share purchases, withdrawals a second officer approves, loan applications through appraisal, the committee and
 * payout, repayments on their due dates), the loan book's daily run catches up before each day's work (interest,
 * penalties, arrears, classification, the month's provisioning on the 1st), and each past month is closed by
 * approval on the 2nd. So today's arrears ageing and PAR are true, not typed in.
 *
 * Every loan state is there: pending (submitted, in committee), approved, rejected by the committee, current, 1–30,
 * 31–90 and over 90 days in arrears, rescheduled, topped up, paid off (one settled early), written off with a
 * recovery, and one brought back by a guarantor's savings. Beside them: savings in each product, shares bought and
 * transferred, an exited member, a deceased member with a nominee, office fines charged as fees, a reversed
 * mobile-money receipt and the operating costs a SACCO pays.
 *
 * What a kernel service does not do yet is added by an ensure* step that waits for it (pending(): the year-end
 * close and dividends, P4.7/P4.8; fines and the welfare fund, P5.4/P5.5; meetings, P5.2; mobile-money
 * collections, P3.1). The heal runs them too, so the demo gains each part the day its service lands.
 */
final class SaccoDemoBuilder
{
    /**
     * Bumped when a build changes what the demo holds. A live demo built by an older version is rebuilt on the next
     * run, not healed.
     */
    public const BUILD = 1;

    /** The manager's delegated approval limit: loans above it go to the committee. */
    public const DELEGATED_LIMIT = 5_000_000;

    /** @var list<string> sample entries a rule refused (logged, never fatal) */
    public array $skipped = [];

    /** The company being built (so a build that fails can be cleaned up). */
    public ?int $companyId = null;

    private array $data;

    private Company $co;

    private int $cid;

    private string $tz;

    private Carbon $realNow;

    private Carbon $today;

    private Carbon $start;

    private string $token;

    /** @var array<string, User> login key => user (plus committee2, committee3) */
    private array $users = [];

    /** @var array<int, SaccoMember> data index => member */
    private array $members = [];

    /** @var array<int, array<string, int>> data index => product code => savings account id */
    private array $accounts = [];

    /** @var array<int, array{cmp: int, ord: int, school: string, active_from: string, gone_on: ?string}> */
    private array $profile = [];

    /** @var array<string, int> product code => loan product id */
    private array $loanProducts = [];

    /** @var array<string, list<array{t: int, fn: callable}>> Y-m-d => the day's work */
    private array $calendar = [];

    /** @var array<string, array<string, mixed>> scenario tag => what it is and how it pays */
    private array $scenarios = [];

    /** @var array<string, int> scenario tag => loan id */
    private array $loans = [];

    /** @var list<int> loan ids repaid on time from their due dates (the heal keeps them so) */
    private array $payers = [];

    /** @var array<int, true> data indexes of members whose loan is meant to fall behind (no withdrawals, no guarantees) */
    private array $troubled = [];

    private int $consentFile = 0;

    public function __construct()
    {
        $this->data = require dirname(__DIR__, 4).'/database/data/demo_sacco.php';
    }

    /** @return array<string, mixed> the data file */
    public static function data(): array
    {
        return require dirname(__DIR__, 4).'/database/data/demo_sacco.php';
    }

    /**
     * A new, complete demo SACCO. Its logins carry temporary addresses until SaccoDemo hands it the published ones.
     *
     * @return array{company: Company, users: array<string, User>, snapshot: array<string, mixed>}
     */
    public function build(): array
    {
        $clock = Carbon::getTestNow();
        $this->realNow = now()->copy();
        $this->tz = (string) config('demo.sacco.timezone', 'Africa/Kampala');
        $this->today = $this->realNow->copy()->setTimezone($this->tz)->startOfDay();
        $this->start = $this->today->copy()->startOfMonth()->subMonthsNoOverflow(max(3, (int) config('demo.sacco.months', 18)));
        $this->token = strtolower(Str::random(6));
        mt_srand((int) $this->today->format('Ymd'));

        $guards = [];
        SaccoDemo::$building = true;
        try {
            OnboardingEvents::muted(function () use (&$guards) {
                $this->at($this->start, 7, 30);
                $this->createCompany();
                $guards = $this->actAs($this->users['chair']);
                $this->setUpBooks();
                $this->founders();
                $this->plan();
                $this->simulate($this->start, $this->today);
                $this->finishingTouches();
            });
        } finally {
            SaccoDemo::$building = false;
            Carbon::setTestNow($clock);
            $this->restore($guards);
        }

        return ['company' => $this->co->fresh(), 'users' => array_map(fn (User $u) => $u->fresh(), $this->users), 'snapshot' => $this->snapshot()];
    }

    /** Pick up a live demo (for the heal) from its snapshot. @param array<string, mixed> $snap */
    public function load(Company $company, array $snap): self
    {
        $this->co = $company;
        $this->cid = (int) $company->id;
        $this->companyId = $this->cid;
        $this->tz = (string) config('demo.sacco.timezone', 'Africa/Kampala');
        $this->realNow = now()->copy();
        $this->today = $this->realNow->copy()->setTimezone($this->tz)->startOfDay();
        foreach ((array) ($snap['users'] ?? []) as $key => $id) {
            if ($u = User::withoutGlobalScopes()->find((int) $id)) {
                $this->users[$key] = $u;
            }
        }
        $this->payers = array_map('intval', (array) ($snap['payers'] ?? []));
        $this->loans = array_map('intval', (array) ($snap['loans'] ?? []));

        return $this;
    }

    /**
     * The heal's half: bring the books up to today (the loan book's run, the on-time payers' instalments, every past
     * month closed) and run the steps that wait for a kernel service. Never adds a row twice.
     *
     * @return array{repairs: list<string>, pending: list<string>}
     */
    public function bringForward(): array
    {
        $clock = Carbon::getTestNow();
        $repairs = [];
        $guards = isset($this->users['chair']) ? $this->actAs($this->users['chair']) : [];
        SaccoDemo::$building = true;
        try {
            OnboardingEvents::muted(function () use (&$repairs) {
                $paid = 0;
                $last = SaccoPeriod::withoutGlobalScopes()->where('company_id', $this->cid)->where('status', 'closed')->max('ends_on');
                $from = Carbon::parse($last ?? $this->today->toDateString(), $this->tz)->addDay()->startOfDay();
                for ($day = $from->copy(); $day->lte($this->today); $day->addDay()) {
                    $this->at($day, 8, 0);
                    $this->quietly(fn () => (new LoanJobs())->run($this->cid));
                    foreach ($this->payers as $loanId) {
                        $paid += $this->payDue($loanId, $day) ? 1 : 0;
                    }
                    if ($day->day === 2 || ($day->day > 2 && $day->equalTo($this->today))) {
                        $closed = $this->closeEndedMonths($day);
                        if ($closed > 0) {
                            $repairs[] = "{$closed} month(s) closed";
                        }
                    }
                }
                if ($paid > 0) {
                    $repairs[] = "{$paid} instalment(s) paid on time";
                }
                $this->at($this->today, 8, 5);
                foreach ($this->ensureSteps() as $step => $result) {
                    if ($result === 'added') {
                        $repairs[] = $step;
                    }
                }
            });
        } finally {
            SaccoDemo::$building = false;
            Carbon::setTestNow($clock);
            $this->restore($guards);
        }

        return ['repairs' => $repairs, 'pending' => $this->pending()];
    }

    // ── The company, its team and its products ────────────────────────────

    private function createCompany(): void
    {
        $s = $this->data['sacco'];
        $chair = $this->newLogin('chair', $this->data['logins']['chair']['name']);

        $co = new Company();
        $co->owner_id = $chair->id; // created hook: the chair's company_id
        $co->name = (string) $s['name'];
        $co->status = 'Active';
        $co->is_demo = true;
        $co->demo_parent_id = null;
        $co->currency = 'UGX';
        $co->country = 'UG';
        $co->timezone = $this->tz;
        $co->business_type = 'sacco';
        $co->address = (string) $s['address'];
        $co->phone_number = (string) $s['phone'];
        $co->email = (string) $s['email'];
        $co->license_expire = now()->addYears(5);
        $co->enabled_modules = ['sacco'];
        $co->receipt_channels = [];
        $co->onboarding_state = ['step' => 'done', 'completed_steps' => config('onboarding.steps'), 'skipped_steps' => [], 'completed_at' => now()->toIso8601String(),
            'dismissed_checklist' => true, 'demo' => true, 'version' => OnboardingService::STATE_VERSION,
            SaccoDemo::MARK => ['building' => true]]; // marks it as the SACCO demo's from the start (a dead build is cleaned up)
        $co->save();
        $this->co = $co->fresh();
        $this->cid = (int) $co->id;
        $this->companyId = $this->cid;

        CompanyMember::create(['company_id' => $co->id, 'user_id' => $chair->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $chair->refresh();
        User::ensureCompanyOwnerRole($chair);
        $this->users['chair'] = $chair->fresh();

        if (! Plan::where('slug', 'akiba-enterprise')->exists()) {
            (new AkibaPlanSeeder())->run();
        }
        Subscription::create(['company_id' => $co->id, 'plan_id' => Plan::where('slug', 'akiba-enterprise')->value('id'), 'status' => 'active', 'provider' => 'demo',
            'starts_at' => now(), 'ends_at' => now()->addYears(5)]);
        $this->co = $this->co->fresh();
    }

    /** A login with a temporary address (the published one is given at hand-over) and a random password for now. */
    private function newLogin(string $key, string $name): User
    {
        [$first, $last] = explode(' ', $name, 2) + [1 => ''];
        $u = new User();
        $u->first_name = $first;
        $u->last_name = $last;
        $u->name = $name;
        $u->email = $this->tempEmail($key);
        $u->username = $u->email;
        $u->phone_number = null;
        $u->phone_e164 = null;
        $u->password = Hash::make(Str::random(40));
        $u->status = 'Active';
        $u->save();

        return $u;
    }

    private function tempEmail(string $key): string
    {
        return "{$key}+build-{$this->token}@".$this->data['sacco']['domain'];
    }

    private function setUpBooks(): void
    {
        $chair = $this->users['chair'];
        app(SaccoSetupService::class)->setup($this->co, ['mode' => 'office', 'legal_name' => $this->data['sacco']['name'], 'short_name' => $this->data['sacco']['short_name'],
            'registration_no' => $this->data['sacco']['registration_no']], $chair);
        $this->co = $this->co->fresh();

        // The team, made the way the Team screen makes them, then seated in their offices.
        $team = app(TeamService::class);
        foreach ($this->data['logins'] as $key => $login) {
            if ($key === 'chair') {
                continue;
            }
            $this->users[$key] = $team->createMember($this->co, $chair, $login['name'], $this->tempEmail($key), null, Str::password(24, symbols: false), $login['role']);
        }
        foreach ($this->data['officers'] as $i => $o) {
            $this->users['committee'.($i + 2)] = $team->createMember($this->co, $chair, $o['name'], $this->tempEmail('committee'.($i + 2)), null, Str::password(24, symbols: false), $o['role']);
        }
        $officers = new OfficerService();
        $since = $this->start->copy()->subYears(2)->toDateString();
        foreach (array_merge($this->data['logins'], ['committee2' => $this->data['officers'][0], 'committee3' => $this->data['officers'][1]]) as $key => $login) {
            if ($key === 'chair' || empty($login['position'])) {
                continue;
            }
            $officers->appoint($this->cid, array_filter(['position' => $login['position'], 'user_id' => (int) $this->users[$key]->id, 'starts_on' => $since,
                'approval_limit_minor' => $login['position'] === 'manager' ? self::DELEGATED_LIMIT : null], fn ($v) => $v !== null), (int) $chair->id);
        }

        // Savings products: the two defaults, a fixed deposit and a holiday account.
        $savings = new SavingsProducts();
        $savings->create($this->co, ['code' => 'FXD', 'name' => 'Fixed deposit (12 months)', 'kind' => 'fixed', 'term_months' => 12, 'interest_rate_bp' => 900,
            'min_opening_minor' => 1_000_000, 'early_withdrawal_penalty_bp' => 200], $chair);
        $savings->create($this->co, ['code' => 'HOL', 'name' => 'Holiday savings', 'kind' => 'voluntary', 'interest_rate_bp' => 300, 'max_withdrawals_per_month' => 2], $chair);

        // Loan products: the default, made a teachers' development loan, a school-fees loan and an emergency loan.
        $loans = new LoanProducts();
        $ord = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $this->cid)->where('code', 'ORD')->firstOrFail();
        $loans->update($this->cid, (int) $ord->id, ['name' => 'Development loan', 'rate_bp' => 150, 'penalty_rate_bp' => 75, 'max_term' => 36, 'max_amount_minor' => 30_000_000,
            'guarantee_cover_bp' => 5000, 'min_membership_days' => 180], (int) $chair->id);
        $base = SaccoLoanProductRules::default('office', 'UGX');
        $sch = $loans->create($this->cid, array_merge($base, ['code' => 'SCH', 'name' => 'School fees loan', 'rate_bp' => 125, 'penalty_rate_bp' => 60, 'max_term' => 12,
            'max_amount_minor' => 8_000_000, 'min_amount_minor' => 200_000, 'guarantors_required' => 1, 'guarantee_cover_bp' => 5000, 'min_membership_days' => 90]), (int) $chair->id);
        $emg = $loans->create($this->cid, array_merge($base, ['code' => 'EMG', 'name' => 'Emergency loan', 'rate_bp' => 200, 'penalty_rate_bp' => 100, 'max_term' => 6,
            'max_amount_minor' => 3_000_000, 'min_amount_minor' => 100_000, 'guarantors_required' => 1, 'guarantee_cover_bp' => 5000, 'min_membership_days' => 60,
            'savings_multiplier_bp' => 20000, 'allow_top_up' => false]), (int) $chair->id);
        $this->loanProducts = ['ORD' => (int) $ord->id, 'SCH' => (int) $sch->id, 'EMG' => (int) $emg->id];
    }

    // ── Members and their opening balances ─────────────────────────────────

    private function founders(): void
    {
        $founding = (int) $this->data['founding'];
        $manager = $this->users['manager'];
        foreach (array_slice($this->data['members'], 0, $founding) as $i => $m) {
            $this->profile[$i] = $this->profileFor($i);
            $joined = $this->start->copy()->subMonths(18 + (($i * 37) % 96))->startOfMonth()->addDays(($i * 11) % 27)->toDateString();
            $this->members[$i] = app(MemberService::class)->register($this->co, $this->memberData($i, $m, $joined), $manager, ['status' => 'active', 'import_key' => 'demo-'.$i])['member'];
        }
        // A second officer checks each member's identity (member_kyc: the manager captured them, the secretary checks).
        foreach ($this->members as $member) {
            $a = app(SaccoApprovals::class)->request($this->co, 'member_kyc', ['member_id' => (int) $member->id, 'decision' => 'verified'], $manager);
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['secretary'], 'National ID seen and copied.');
        }

        // The balances brought from the old books, as on the first day.
        $rows = ['member_no,shares,savings_ORD,savings_CMP'];
        $memberTotal = 0;
        $par = (int) \App\Models\SaccoSetting::for($this->cid)->share_par_minor;
        foreach ($this->members as $i => $member) {
            $shares = 10 + (($i * 53) % 140);
            $ord = $this->round((100_000 + (($i * 7919) % 60) * 60_000) * ($i % 5 === 0 ? 2 : 1));
            $cmp = $this->round(600_000 + (($i * 4271) % 40) * 90_000);
            if ($i === 0) {
                [$shares, $ord, $cmp] = [60, 3_400_000, 2_100_000];
            }
            $rows[] = "{$member->member_no},{$shares},{$ord},{$cmp}";
            $memberTotal += $ord + $cmp + $shares * $par;
        }
        $reserve = 42_000_000;
        $prior = 18_500_000;
        $cash = 2_500_000;
        $bank = $memberTotal + $reserve + $prior - $cash;
        $ledger = ['account_code,debit,credit,memo', "1100,{$bank},0,Bank balance at the move", "1010,{$cash},0,Cash in the office safe",
            "3200,0,{$reserve},Statutory reserve brought forward", "3400,0,{$prior},Retained surplus of earlier years"];
        foreach (['members' => implode("\n", $rows), 'ledger' => implode("\n", $ledger)] as $kind => $csv) {
            $a = (new OpeningBalances())->request($this->co, $kind, $csv, $manager, $this->start->toDateString());
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['treasurer'], 'Agreed to the audited balances of the old books.');
        }
        foreach ($this->members as $i => $member) {
            $this->accounts[$i] = SaccoSavingsAccount::withoutGlobalScopes()->join('sacco_savings_products as p', 'p.id', '=', 'sacco_savings_accounts.product_id')
                ->where('sacco_savings_accounts.company_id', $this->cid)->where('sacco_savings_accounts.member_id', $member->id)
                ->pluck('sacco_savings_accounts.id', 'p.code')->map(fn ($id) => (int) $id)->all();
        }
    }

    /** Amounts kept to whole thousands of shillings (UGX has no smaller unit: minor = shillings). */
    private function round(int $minor): int
    {
        return intdiv($minor, 1000) * 1000;
    }

    /** @return array{cmp: int, ord: int, school: string, active_from: string, gone_on: ?string} */
    private function profileFor(int $i): array
    {
        $schools = $this->data['schools'];

        return ['cmp' => [50_000, 60_000, 80_000, 100_000, 120_000][$i % 5], 'ord' => [0, 0, 30_000, 50_000, 80_000, 150_000][($i * 7) % 6],
            'school' => $schools[$i % count($schools)], 'active_from' => $this->start->toDateString(), 'gone_on' => null];
    }

    /** @param array{0: string, 1: string, 2: string, 3: int} $m @return array<string, mixed> */
    private function memberData(int $i, array $m, string $joined): array
    {
        [$first, $last, $gender, $year] = $m;
        $counties = $this->data['subcounties'];

        return ['first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'date_of_birth' => sprintf('%04d-%02d-%02d', $year, 1 + ($i * 5) % 12, 1 + ($i * 7) % 27),
            'nin' => self::nin($gender, $year, $i), 'phone' => sprintf('+2567000%05d', 10_101 + $i * 7), 'email' => $i === (int) $this->data['member_login']['member'] ? null
                : strtolower($first.'.'.$last).'@'.$this->data['sacco']['domain'], 'district' => 'Wakiso', 'subcounty' => $counties[$i % count($counties)],
            'occupation' => 'Teacher', 'employer_name' => $this->profile[$i]['school'], 'payroll_no' => sprintf('TCH-%05d', 31_000 + $i * 13),
            'joined_on' => $joined, 'consent_data' => true, 'consent_messages' => true, 'consent_credit_bureau' => $i % 3 !== 0];
    }

    /** A clearly fictional NIN in the card's shape: CM (men) or CF (women), the birth year, "DEMO", a number. */
    public static function nin(string $gender, int $year, int $i): string
    {
        return ($gender === 'male' ? 'CM' : 'CF').substr((string) $year, -2).'DEMO'.sprintf('%04d', $i + 1).chr(65 + $i % 26).chr(65 + ($i * 7) % 26);
    }

    // ── The plan: who borrows what, and what happens to each loan ─────────

    private function plan(): void
    {
        $T = $this->today;
        $ago = fn (int $months, int $days = 0) => $T->copy()->subMonthsNoOverflow($months)->subDays($days);

        // Loans: [tag, member, product, amount, term, disbursed on, how it is repaid].
        $plan = [
            // Current, repaid on time.
            ['current_1', 0, 'ORD', 6_000_000, 24, $ago(7, 3), ['pay' => 'all']],
            ['current_2', 1, 'ORD', 15_000_000, 30, $ago(13, 9), ['pay' => 'all']],
            ['current_3', 4, 'SCH', 2_400_000, 10, $ago(4, 12), ['pay' => 'all']],
            ['current_4', 5, 'ORD', 9_000_000, 24, $ago(9, 18), ['pay' => 'all']],
            ['current_5', 10, 'ORD', 6_500_000, 18, $ago(2, 6), ['pay' => 'all']],
            ['current_6', 15, 'SCH', 1_800_000, 6, $ago(1, 20), ['pay' => 'all']],
            ['current_7', 20, 'ORD', 14_000_000, 30, $ago(11, 2), ['pay' => 'all']],
            ['current_8', 25, 'EMG', 1_200_000, 6, $ago(3, 25), ['pay' => 'all']],
            ['current_9', 30, 'ORD', 10_000_000, 24, $ago(5, 14), ['pay' => 'all']],
            ['current_10', 35, 'ORD', 7_000_000, 18, $ago(6, 8), ['pay' => 'all']],
            // Paid off.
            ['paid_1', 2, 'EMG', 1_500_000, 6, $ago(15, 4), ['pay' => 'all']],
            ['paid_2', 6, 'SCH', 2_000_000, 6, $ago(11, 16), ['pay' => 'all']],
            ['paid_3', 8, 'ORD', 3_600_000, 12, $ago(16, 22), ['pay' => 'all']],
            ['paid_early', 12, 'ORD', 4_000_000, 12, $ago(10, 10), ['pay' => 'all', 'settle_after' => 5]],
            // In arrears: the last instalment(s) missed, so the oldest unpaid one is about 17, 40, 70, 110 and 150 days old.
            ['arrears_17', 3, 'ORD', 3_000_000, 12, $ago(7, 17), ['pay' => 'until', 'missed' => 1]],
            ['arrears_40', 7, 'SCH', 2_200_000, 10, $ago(6, 10), ['pay' => 'until', 'missed' => 2]],
            ['arrears_70', 9, 'ORD', 4_200_000, 18, $ago(8, 9), ['pay' => 'until', 'missed' => 3]],
            ['arrears_110', 11, 'ORD', 3_500_000, 18, $ago(10, 19), ['pay' => 'until', 'missed' => 4]],
            ['arrears_150', 13, 'ORD', 2_800_000, 12, $ago(11, 29), ['pay' => 'until', 'missed' => 5, 'part' => 400_000]],
            // Rescheduled after two missed instalments, then repaid on the new schedule.
            ['rescheduled', 14, 'ORD', 4_800_000, 12, $ago(12, 5), ['pay' => 'reschedule', 'paid' => 4, 'gap' => 2, 'term' => 18]],
            // Topped up: the old loan settled by a bigger new one four months ago; the new one is current.
            ['topped_old', 16, 'ORD', 3_000_000, 18, $ago(12, 11), ['pay' => 'all', 'top_up' => ['months_ago' => 4, 'days' => 2, 'amount' => 6_500_000, 'term' => 24]]],
            // Written off after it went to loss, with a recovery afterwards.
            ['written_off', 18, 'ORD', 3_200_000, 12, $ago(17, 13), ['pay' => 'until', 'paid' => 2, 'write_off' => $ago(5, 6), 'recover' => [$ago(2, 15), 350_000]]],
            // The borrower stopped; two months late, the guarantor's savings paid the arrears; then the borrower resumed.
            ['guarantor_paid', 19, 'ORD', 2_400_000, 12, $ago(9, 7), ['pay' => 'guarantor', 'paid' => 4, 'gap' => 2]],
            // Decided at the committee's sitting three weeks ago: one approved (paid out since), one rejected.
            ['committee_yes', 22, 'ORD', 12_000_000, 36, $ago(0, 12), ['pay' => 'all', 'committee' => $ago(0, 19)]],
        ];
        foreach ($plan as [$tag, $who, $product, $amount, $term, $on, $how]) {
            $this->scenarios[$tag] = ['tag' => $tag, 'member' => $who, 'product' => $product, 'amount' => $amount, 'term' => $term, 'on' => $on->copy()->startOfDay(), 'how' => $how];
            if ($how['pay'] === 'until') {
                $this->troubled[$who] = true;
            }
            $this->scheduleLoan($tag);
        }
        $this->troubled[19] = true; // the guarantor-paid loan's borrower

        $this->routine();
        $this->lifeEvents();
    }

    /** The application, appraisal, decision, contract and payout of a planned loan, on the days before its payout. */
    private function scheduleLoan(string $tag): void
    {
        $s = $this->scenarios[$tag];
        $d = $s['on'];
        $decide = isset($s['how']['committee']) ? $s['how']['committee'] : $d->copy()->subDays(6);
        $this->on($decide->copy()->subDays(4), 10, fn () => $this->apply($tag, $decide->copy()->subDays(4)));
        $this->on($decide->copy()->subDays(2), 11, fn () => $this->appraise($tag));
        $this->on($decide, 14, fn () => $this->decide($tag));
        $this->on($d->copy()->subDays(1), 10, fn () => $this->sign($tag));
        $this->on($d, 11, fn () => $this->disburse($tag));
    }

    /** The SACCO's every month: payroll check-off, the counter, shares, withdrawals, costs. */
    private function routine(): void
    {
        for ($m = $this->start->copy(); $m->lte($this->today); $m->addMonthNoOverflow()) {
            $month = $m->copy();
            $payday = $this->workingDayOnOrBefore($month->copy()->day(min(27, $month->daysInMonth)));
            if ($payday->lte($this->today)) {
                $this->on($payday, 9, fn () => $this->checkOff($month));
                $this->on($payday, 15, fn () => $this->operatingCosts($month));
            }
            for ($k = 0, $n = max(0, (int) config('demo.sacco.counter_per_month', 14)); $k < $n; $k++) {
                $day = $this->workingDayOnOrBefore($month->copy()->addDays(mt_rand(0, $month->daysInMonth - 1)));
                if ($day->lt($this->start) || $day->gt($this->today)) {
                    continue;
                }
                $who = mt_rand(0, 59);
                $kind = mt_rand(1, 100);
                $this->on($day, 10 + $k % 6, fn () => match (true) {
                    $kind <= 45 => $this->counterDeposit($who),
                    $kind <= 62 => $this->sharePurchase($who),
                    $kind <= 72 => $this->holidayDeposit($who),
                    default => $this->counterWithdrawal($who, $kind > 96),
                });
            }
        }
    }

    /** Joiners, fixed deposits, the exit, the death, the transfers, the fines, the reversed receipt. */
    private function lifeEvents(): void
    {
        $T = $this->today;
        // Twelve teachers join over the months (registered, entrance fee, shares, ID checked, admitted).
        foreach (range(48, 59) as $n => $i) {
            $day = $this->workingDayOnOrBefore($this->start->copy()->addMonthsNoOverflow(1 + $n)->addDays(8 + ($n * 3) % 15));
            if ($day->lt($T)) {
                $this->on($day, 9, fn () => $this->join($i));
            }
        }
        // Fixed deposits: six members lock money away for a year; one has matured and was paid back.
        foreach ([[17, 3, 5_000_000], [21, 6, 3_000_000], [27, 9, 8_000_000], [33, 2, 2_000_000], [40, 12, 4_000_000], [0, 8, 1_500_000]] as [$who, $monthsIn, $amount]) {
            $this->on($this->workingDayOnOrBefore($this->start->copy()->addMonthsNoOverflow($monthsIn)->addDays(4)), 12, fn () => $this->fixedDeposit($who, $amount));
        }
        $this->on($this->workingDayOnOrBefore($this->start->copy()->addMonthsNoOverflow(14)->addDays(5)), 12, fn () => $this->fixedMatures(33));
        // Money not lent out earns interest: a fixed deposit with a bank, placed by the treasurer (a manual journal).
        $this->on($this->workingDayOnOrBefore($this->start->copy()->addMonthsNoOverflow(2)->addDays(10)), 15, fn () => $this->placeWithBank(150_000_000));
        // Shares change hands: one member sells some to another (by approval).
        $this->on($this->workingDayOnOrBefore($T->copy()->subMonthsNoOverflow(9)->day(14)), 11, fn () => $this->shareTransfer(26, 29, 15, 'Sold to a colleague at par'));
        // A member leaves the SACCO: shares to another member, savings paid out, the member exited.
        $this->on($this->workingDayOnOrBefore($T->copy()->subMonthsNoOverflow(3)->day(10)), 11, fn () => $this->memberExits(47, 46));
        // A member dies; the nomination made earlier names who receives the savings.
        $this->on($this->workingDayOnOrBefore($this->start->copy()->addMonthsNoOverflow(2)->day(12)), 12, fn () => $this->nominate(34));
        $this->on($this->workingDayOnOrBefore($T->copy()->subMonthsNoOverflow(4)->day(18)), 12, fn () => $this->dies(34));
        // Office fines, charged to savings as a fee: absence from the AGM, a late loan repayment.
        $agm = $this->workingDayOnOrBefore(Carbon::create($this->start->year + 1, 3, 28, 0, 0, 0, $this->tz));
        if ($agm->lt($T)) {
            foreach ([23, 31, 38, 42, 44] as $who) {
                $this->on($agm->copy()->addDays(3), 13, fn () => $this->fine($who, 20_000, 'Fine: absent from the annual general meeting without apology'));
            }
        }
        $this->on($this->workingDayOnOrBefore($T->copy()->subMonthsNoOverflow(1)->day(5)), 13, fn () => $this->fine(3, 15_000, 'Fine: loan instalment paid late'));
        // A mobile-money deposit the network reversed (a duplicate push): the receipt reversed by a second officer.
        $this->on($this->workingDayOnOrBefore($T->copy()->subMonthsNoOverflow(2)->day(9)), 15, fn () => $this->momoReversal(24));
    }

    // ── Running the days ──────────────────────────────────────────────────

    private function on(\Carbon\Carbon $day, int $hour, callable $fn): void
    {
        $key = $day->copy()->setTimezone($this->tz)->toDateString();
        if ($key < $this->start->toDateString() || $key > $this->today->toDateString()) {
            return;
        }
        $this->calendar[$key][] = ['t' => $hour * 100 + count($this->calendar[$key] ?? []), 'fn' => $fn];
    }

    private function simulate(\Carbon\Carbon $from, \Carbon\Carbon $to): void
    {
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $this->at($day, 7, 0);
            if ($day->day === 1 || $day->equalTo($from)) {
                (new PeriodService())->ensureAhead($this->cid, $day, 2);
            }
            // The loan book's run matters on a day with work, a due date or a month's turn; it catches up the rest.
            $work = $this->calendar[$key] ?? [];
            if ($work !== [] || $day->day <= 2 || $day->equalTo($to) || $this->dueOn($key)) {
                (new LoanJobs())->run($this->cid);
            }
            if ($day->day === 2) {
                $this->at($day, 8, 0);
                $this->closeEndedMonths($day);
            }
            usort($work, fn ($a, $b) => $a['t'] <=> $b['t']);
            foreach ($work as $w) {
                $this->at($day, intdiv($w['t'], 100), (int) ($w['t'] % 100) * 3 % 60);
                ($w['fn'])();
            }
            if ($this->payers !== []) {
                $this->at($day, 16, 30);
                foreach ($this->payers as $loanId) {
                    $this->payDue($loanId, $day);
                }
            }
        }
    }

    private function dueOn(string $day): bool
    {
        return SaccoLoanScheduleLine::withoutGlobalScopes()->where('company_id', $this->cid)->where('due_on', $day)->where('status', '!=', 'superseded')->exists();
    }

    /** Close every ended month still open, oldest first, by approval (the treasurer asks, the manager checks). */
    private function closeEndedMonths(\Carbon\Carbon $day): int
    {
        $n = 0;
        $open = SaccoPeriod::withoutGlobalScopes()->where('company_id', $this->cid)->where('status', 'open')->where('ends_on', '<', $day->copy()->startOfMonth()->toDateString())
            ->orderBy('starts_on')->get();
        foreach ($open as $period) {
            $ok = $this->quietly(function () use ($period) {
                $a = app(SaccoApprovals::class)->request($this->co, 'period_close', ['period_id' => (int) $period->id], $this->users['treasurer']);
                app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['manager'], 'Bank reconciled; the trial balance agrees.');

                return true;
            });
            if ($ok !== true) {
                break; // months close in order
            }
            $n++;
        }

        return $n;
    }

    // ── Loans ─────────────────────────────────────────────────────────────

    private function apply(string $tag, \Carbon\Carbon $on): void
    {
        $s = &$this->scenarios[$tag];
        $member = $this->members[$s['member']] ?? null;
        if ($member === null) {
            return;
        }
        $purposes = $this->data['purposes'][$s['product']];
        $data = ['member_id' => (int) $member->id, 'product_id' => $this->loanProducts[$s['product']], 'applied_minor' => $s['amount'], 'term' => $s['term'],
            'purpose' => $purposes[crc32($tag) % count($purposes)], 'disbursement_channel' => $s['member'] % 3 === 0 ? 'savings' : 'bank'];
        $this->topUpSavings($s['member'], $s['amount'], $s['product']);
        $loan = $this->quietly(fn () => (new LoanApplications())->create($this->co, $data, $this->users['loans'], ['submit' => true]));
        if ($loan instanceof SaccoLoan) {
            $this->loans[$tag] = (int) $loan->id;
            $this->guarantee($tag, $loan);
        }
    }

    /** A borrower brings their savings up to what the product's multiple needs (a teacher saves before asking). */
    private function topUpSavings(int $who, int $amount, string $product): void
    {
        $mult = $product === 'EMG' ? 2 : 3;
        $need = intdiv($amount, $mult) + 200_000;
        $free = MemberFunds::freeSavings($this->cid, (int) $this->members[$who]->id);
        if ($free < $need && isset($this->accounts[$who]['ORD'])) {
            $this->tx(['kind' => 'deposit', 'savings_account_id' => $this->accounts[$who]['ORD'], 'amount_minor' => $this->round($need - $free + 100_000), 'channel' => 'bank',
                'reference' => 'Transfer from Stanbic', 'narration' => 'Savings before a loan application'], $this->users['teller']);
        }
    }

    /** The guarantors the product asks for, each covering an equal part, with their signed consent forms. */
    private function guarantee(string $tag, SaccoLoan $loan): void
    {
        $product = SaccoLoanProduct::withoutGlobalScopes()->findOrFail($loan->product_id);
        $count = (int) $product->guarantors_required;
        if ($count < 1) {
            return;
        }
        $total = intdiv((int) $loan->applied_minor * (int) $product->guarantee_cover_bp, 10000);
        $each = (int) ceil($total / $count / 1000) * 1000;
        $borrower = $this->scenarios[$tag]['member'];
        $picked = 0;
        foreach ($this->guarantorCandidates($borrower, $each) as $i) {
            $g = $this->quietly(fn () => (new Guarantors())->add($this->co, (int) $loan->id, (int) $this->members[$i]->id, $each, $this->users['loans']));
            if ($g instanceof SaccoLoanGuarantor) {
                $this->quietly(fn () => (new Guarantors())->consentWithForm($this->co, (int) $g->id, $this->consentFile(), $this->users['loans']));
                if (++$picked >= $count) {
                    break;
                }
            }
        }
    }

    /** Members who can stand guarantee for $each now: active, not the borrower, not in trouble, most free savings first. @return list<int> */
    private function guarantorCandidates(int $borrower, int $each): array
    {
        $out = [];
        foreach ($this->members as $i => $m) {
            if ($i === $borrower || isset($this->troubled[$i]) || in_array($i, [34, 46, 47], true)) {
                continue;
            }
            $status = SaccoMember::withoutGlobalScopes()->whereKey($m->id)->value('status');
            if ($status !== 'active') {
                continue;
            }
            $exp = Guarantors::exposure($this->cid, (int) $m->id);
            // A lien sits whole on one savings account (MemberFunds::hold): the freest account must cover it.
            $best = (int) DB::table('sacco_savings_accounts')->where('company_id', $this->cid)->where('member_id', $m->id)->whereIn('status', ['active', 'dormant'])
                ->selectRaw('COALESCE(MAX(balance_minor - held_minor), 0) AS f')->value('f');
            $free = min($best, MemberFunds::freeSavings($this->cid, (int) $m->id) - ((int) $exp['guaranteed_minor'] - (int) $exp['held_minor']));
            if ($free >= $each && (int) $exp['count'] < 2) {
                $out[$i] = $free;
            }
        }
        arsort($out);
        $keys = array_keys($out);
        // Not always the richest two: spread the guarantees around.
        $offset = $keys === [] ? 0 : crc32((string) $borrower) % max(1, min(6, count($keys)));

        return array_merge(array_slice($keys, $offset), array_slice($keys, 0, $offset));
    }

    private function consentFile(): int
    {
        if ($this->consentFile === 0) {
            $path = 'sacco/demo/'.$this->cid.'/guarantor-consent-form.pdf';
            Storage::disk('public')->put($path, app('dompdf.wrapper')->loadHTML('<h2>Guarantor consent form</h2><p>Sample form for the Akiba Ledger demo. Every name in this demo is made up.</p>')->output());
            $this->consentFile = (int) StoredFile::create(['company_id' => $this->cid, 'uuid' => (string) Str::uuid(), 'purpose' => 'sacco_guarantee', 'path' => $path,
                'mime' => 'application/pdf', 'size_bytes' => (int) Storage::disk('public')->size($path), 'uploaded_by_id' => $this->users['loans']->id])->id;
        }

        return $this->consentFile;
    }

    private function appraise(string $tag): void
    {
        if (! isset($this->loans[$tag])) {
            return;
        }
        $s = $this->scenarios[$tag];
        $income = 1_400_000 + ($s['member'] % 7) * 250_000;
        $this->quietly(fn () => (new LoanApplications())->appraise($this->co, $this->loans[$tag], $this->users['loans'], [
            'monthly_income_minor' => $income + intdiv($s['amount'], 8), 'monthly_expenses_minor' => intdiv($income, 3),
            'notes' => 'Payslip and bank statement seen. Salary paid through the payroll check-off; deductions fit within half of the pay.']));
    }

    private function decide(string $tag): void
    {
        if (! isset($this->loans[$tag])) {
            return;
        }
        $id = $this->loans[$tag];
        $loan = SaccoLoan::withoutGlobalScopes()->find($id);
        if ($loan === null || $loan->status !== 'appraised') {
            return;
        }
        if ((int) $loan->approved_minor <= self::DELEGATED_LIMIT && ! $loan->is_insider) {
            $this->quietly(function () use ($id) {
                (new Committee())->requestDelegated($this->co, $id, $this->users['loans']);
                (new Committee())->decide($this->co, $id, $this->users['manager'], 'approve', 'Within the manager\'s limit; guarantors and savings in order.');
            });

            return;
        }
        $this->quietly(function () use ($id) {
            (new Committee())->sendToCommittee($this->co, $id, $this->users['loans']);
            (new Committee())->vote($this->co, $id, $this->users['committee'], 'approve', 'Minutes: the project is sound and the payslip supports the instalment.');
            (new Committee())->vote($this->co, $id, $this->users['committee2'], 'approve', 'Minutes: approved; collateral (land title) to be kept in the safe.');
        });
    }

    private function sign(string $tag): void
    {
        $id = $this->loans[$tag] ?? null;
        if ($id === null || SaccoLoan::withoutGlobalScopes()->whereKey($id)->value('status') !== 'approved') {
            return;
        }
        $this->quietly(function () use ($id, $tag) {
            (new Contracts())->generate($this->co, $id, $this->users['loans']);
            (new Contracts())->sign($this->co, $id, $this->users['loans'], ['witness_name' => $this->witness($tag), 'signed_on' => $this->day()]);
        });
    }

    private function witness(string $tag): string
    {
        $names = ['Ruth Nakiwala (head teacher)', 'Samuel Mayanja (deputy head teacher)', 'Agnes Nakazzi (LC1 chairperson)', 'Charles Kiggundu (school bursar)'];

        return $names[crc32($tag) % count($names)];
    }

    private function disburse(string $tag): void
    {
        $id = $this->loans[$tag] ?? null;
        if ($id === null) {
            return;
        }
        $loan = SaccoLoan::withoutGlobalScopes()->find($id);
        if ($loan === null || $loan->status !== 'signed') {
            return;
        }
        $channel = (string) ($loan->disbursement_channel ?: 'bank');
        $ok = $this->quietly(function () use ($id, $channel, $tag) {
            $a = (new Disbursement())->request($this->co, $id, $this->users['treasurer'], ['channel' => $channel, 'bank_reference' => $channel === 'bank' ? 'EFT '.strtoupper(substr(md5($tag), 0, 8)) : null]);
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['manager'], 'Contract signed and witnessed; pay out.');

            return true;
        });
        if ($ok === true) {
            $this->afterDisbursed($tag, $id);
        }
    }

    /** What happens to each loan once it is out: on-time payers join the payers' list; the others get their own story. */
    private function afterDisbursed(string $tag, int $id): void
    {
        $s = $this->scenarios[$tag];
        $how = $s['how'];
        $lines = SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $id)->orderBy('installment_no')->get();
        switch ($how['pay']) {
            case 'all':
                $this->payers[] = $id;
                if (isset($how['settle_after'])) {
                    $line = $lines[$how['settle_after'] - 1] ?? null;
                    if ($line !== null) {
                        $this->on($line->due_on->copy()->addDays(9), 11, fn () => $this->settle($tag));
                    }
                }
                if (isset($how['top_up'])) {
                    $this->planTopUp($tag);
                }
                break;
            case 'until':
                // Paid on time until the last $missed instalments due by today (or after $paid instalments).
                $due = $lines->filter(fn ($l) => $l->due_on->lte($this->today))->values();
                $keep = isset($how['paid']) ? (int) $how['paid'] : max(0, $due->count() - (int) $how['missed']);
                foreach ($due->take($keep) as $l) {
                    $this->on($l->due_on, 16, fn () => $this->payDue($id, $l->due_on->copy()));
                }
                if (isset($how['part']) && ($next = $due[$keep] ?? null) !== null) {
                    $this->on($next->due_on->copy()->addDays(12), 12, fn () => $this->repay($id, $how['part'], 'cash', 'Part payment brought to the office'));
                }
                if (isset($how['write_off'])) {
                    $this->on($how['write_off'], 10, fn () => $this->writeOff($tag));
                    [$when, $amount] = $how['recover'];
                    $this->on($when, 11, fn () => $this->recover($tag, $amount));
                }
                break;
            case 'reschedule':
                foreach ($lines->take((int) $how['paid']) as $l) {
                    $this->on($l->due_on, 16, fn () => $this->payDue($id, $l->due_on->copy()));
                }
                $at = $lines[(int) $how['paid'] + (int) $how['gap']]->due_on->copy()->subDays(5);
                $this->on($at, 10, fn () => $this->reschedule($tag, (int) $how['term']));
                break;
            case 'guarantor':
                foreach ($lines->take((int) $how['paid']) as $l) {
                    $this->on($l->due_on, 16, fn () => $this->payDue($id, $l->due_on->copy()));
                }
                $at = $lines[(int) $how['paid'] + (int) $how['gap']]->due_on->copy()->addDays(3);
                $this->on($at, 10, fn () => $this->guarantorPays($tag));
                break;
        }
    }

    /** Pay what is due on a loan by a day (overdue principal, interest, penalty and fees), by check-off. */
    private function payDue(int $loanId, \Carbon\Carbon $day): bool
    {
        $loan = SaccoLoan::withoutGlobalScopes()->find($loanId);
        if ($loan === null || $loan->status !== 'active') {
            return false;
        }
        $lines = SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loanId)->where('schedule_version', $loan->schedule_version)
            ->where('due_on', '<=', $day->toDateString())->where('status', '!=', 'paid')->get();
        if ($lines->isEmpty()) {
            return false;
        }
        $principal = min((int) $loan->principal_outstanding_minor, (int) $lines->sum(fn ($l) => (int) $l->principal_due_minor - (int) $l->principal_paid_minor));
        $amount = $principal + max(0, (int) $loan->interest_due_minor) + (int) $loan->interest_in_suspense_minor + max(0, (int) $loan->penalty_due_minor)
            + (int) $loan->penalty_in_suspense_minor + max(0, (int) $loan->fees_due_minor);
        if ($amount < 1) {
            return false;
        }

        return $this->repay($loanId, $amount, 'bank', 'Payroll check-off', $day) !== null;
    }

    private function repay(int $loanId, int $amount, string $channel, string $reference, ?\Carbon\Carbon $day = null): mixed
    {
        return $this->quietly(fn () => (new Repayments())->record($this->co, $loanId, ['amount_minor' => $amount, 'channel' => $channel, 'reference' => $reference]
            + ($day !== null ? ['value_date' => $day->toDateString()] : []), $this->users['teller']));
    }

    private function settle(string $tag): void
    {
        $id = $this->loans[$tag] ?? 0;
        $this->quietly(function () use ($id) {
            $q = (new Repayments())->quote($this->cid, $id);
            (new Repayments())->record($this->co, $id, ['amount_minor' => (int) $q['total_minor'], 'channel' => 'bank', 'reference' => 'Early settlement (no fee)'], $this->users['teller'], ['settle' => true]);
        });
    }

    private function planTopUp(string $tag): void
    {
        $t = $this->scenarios[$tag]['how']['top_up'];
        $on = $this->today->copy()->subMonthsNoOverflow($t['months_ago'])->subDays($t['days']);
        $newTag = 'topped_new';
        $this->scenarios[$newTag] = ['tag' => $newTag, 'member' => $this->scenarios[$tag]['member'], 'product' => 'ORD', 'amount' => $t['amount'], 'term' => $t['term'],
            'on' => $on, 'how' => ['pay' => 'all']];
        $this->on($on->copy()->subDays(8), 10, function () use ($tag, $newTag, $t) {
            $old = $this->loans[$tag] ?? null;
            if ($old === null) {
                return;
            }
            $this->topUpSavings($this->scenarios[$tag]['member'], $t['amount'], 'ORD');
            $loan = $this->quietly(fn () => (new Restructure())->applyTopUp($this->co, $old, ['applied_minor' => $t['amount'], 'term' => $t['term'], 'purpose' => 'Top-up: finish the rental rooms',
                'disbursement_channel' => 'savings'], $this->users['loans']));
            if ($loan instanceof SaccoLoan) {
                $this->loans[$newTag] = (int) $loan->id;
                $this->guarantee($newTag, $loan);
            }
        });
        $this->on($on->copy()->subDays(6), 11, fn () => $this->appraise($newTag));
        $this->on($on->copy()->subDays(4), 14, fn () => $this->decide($newTag));
        $this->on($on->copy()->subDays(1), 10, fn () => $this->sign($newTag));
        $this->on($on, 11, fn () => $this->disburse($newTag));
    }

    private function reschedule(string $tag, int $term): void
    {
        $id = $this->loans[$tag] ?? 0;
        $this->quietly(function () use ($id, $term) {
            $a = (new Restructure())->requestReschedule($this->co, $id, $this->users['loans'], ['term' => $term, 'reason' => 'Salary delayed for two months after a payroll transfer; the member asks for smaller instalments.',
                'consent_signed_on' => $this->day(), 'consent_witness' => 'Charles Kiggundu (school bursar)']);
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['committee'], 'Minutes: rescheduled over 18 months with the borrower\'s written consent.');
        });
        $this->payers[] = $id;
    }

    private function guarantorPays(string $tag): void
    {
        $id = $this->loans[$tag] ?? 0;
        $loan = SaccoLoan::withoutGlobalScopes()->find($id);
        if ($loan === null || (int) $loan->days_past_due < 1) {
            return;
        }
        $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('loan_id', $id)->where('consent_status', 'consented')->orderByDesc('held_minor')->first();
        if ($g === null) {
            return;
        }
        $owed = (int) $loan->interest_due_minor + (int) $loan->penalty_due_minor + (int) $loan->fees_due_minor + (int) $loan->interest_in_suspense_minor + (int) $loan->penalty_in_suspense_minor
            + (int) SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $id)->where('schedule_version', $loan->schedule_version)->where('due_on', '<=', $this->day())
                ->where('status', '!=', 'paid')->get()->sum(fn ($l) => (int) $l->principal_due_minor - (int) $l->principal_paid_minor);
        $this->quietly(function () use ($g, $owed) {
            $a = (new GuarantorRecovery())->request($this->co, (int) $g->id, min($owed, (int) $g->held_minor), $this->users['loans'], 'Borrower two instalments behind; demand notice served and not answered.');
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['manager'], 'Recover the arrears from the guarantor\'s held savings.');
        });
        $this->payers[] = $id; // the borrower pays again from the next instalment
    }

    private function writeOff(string $tag): void
    {
        $id = $this->loans[$tag] ?? 0;
        $this->quietly(function () use ($id) {
            $a = (new WriteOffs())->request($this->co, $id, $this->users['loans'], 'Over 180 days in arrears; the borrower left teaching and cannot be traced; demand notices returned.');
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['treasurer'], 'Fully provided for; write off.');
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['chair'], 'Approved by the board.');
        });
    }

    private function recover(string $tag, int $amount): void
    {
        $id = $this->loans[$tag] ?? 0;
        $this->quietly(fn () => (new WriteOffs())->recover($this->co, $id, ['amount_minor' => $amount, 'channel' => 'cash', 'narration' => 'Recovered after the member was traced in Mityana'], $this->users['teller']));
    }

    // ── Savings, shares and the counter ───────────────────────────────────

    /** @param array<string, mixed> $data */
    private function tx(array $data, User $by): mixed
    {
        return $this->quietly(fn () => app(SaccoTransactions::class)->record($this->co, $data, $by)['transaction']);
    }

    private function isActive(int $i): bool
    {
        return isset($this->members[$i]) && SaccoMember::withoutGlobalScopes()->whereKey($this->members[$i]->id)->value('status') === 'active';
    }

    private function checkOff(\Carbon\Carbon $month): void
    {
        foreach ($this->members as $i => $m) {
            if (! $this->isActive($i) || ! isset($this->accounts[$i]['CMP'])) {
                continue;
            }
            $ref = 'Payroll check-off '.$month->format('M Y');
            $this->tx(['kind' => 'deposit', 'savings_account_id' => $this->accounts[$i]['CMP'], 'amount_minor' => $this->profile[$i]['cmp'], 'channel' => 'bank', 'reference' => $ref], $this->users['teller']);
            if ($this->profile[$i]['ord'] > 0 && isset($this->accounts[$i]['ORD']) && ! isset($this->troubled[$i])) {
                $this->tx(['kind' => 'deposit', 'savings_account_id' => $this->accounts[$i]['ORD'], 'amount_minor' => $this->profile[$i]['ord'], 'channel' => 'bank', 'reference' => $ref], $this->users['teller']);
            }
        }
    }

    private function counterDeposit(int $i): void
    {
        if ($this->isActive($i) && isset($this->accounts[$i]['ORD'])) {
            $this->tx(['kind' => 'deposit', 'savings_account_id' => $this->accounts[$i]['ORD'], 'amount_minor' => 50_000 * mt_rand(1, 16), 'channel' => 'cash'], $this->users['teller']);
        }
    }

    private function holidayDeposit(int $i): void
    {
        if (! $this->isActive($i)) {
            return;
        }
        if (! isset($this->accounts[$i]['HOL'])) {
            $acct = $this->quietly(fn () => app(SavingsAccounts::class)->open($this->co, (int) $this->members[$i]->id, $this->savingsProduct('HOL'), $this->users['teller']));
            if (! $acct instanceof SaccoSavingsAccount) {
                return;
            }
            $this->accounts[$i]['HOL'] = (int) $acct->id;
        }
        $this->tx(['kind' => 'deposit', 'savings_account_id' => $this->accounts[$i]['HOL'], 'amount_minor' => 20_000 * mt_rand(1, 6), 'channel' => 'cash'], $this->users['teller']);
    }

    private function savingsProduct(string $code): int
    {
        return (int) SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $this->cid)->where('code', $code)->value('id');
    }

    private function sharePurchase(int $i): void
    {
        if ($this->isActive($i)) {
            $this->quietly(fn () => app(ShareService::class)->purchase($this->co, ['member_id' => (int) $this->members[$i]->id, 'shares_count' => mt_rand(1, 8), 'channel' => 'cash'], $this->users['teller']));
        }
    }

    /** A withdrawal at the counter; a large one waits for the treasurer's approval. */
    private function counterWithdrawal(int $i, bool $large): void
    {
        if (! $this->isActive($i) || ! isset($this->accounts[$i]['ORD']) || isset($this->troubled[$i])) {
            return;
        }
        $account = SaccoSavingsAccount::withoutGlobalScopes()->find($this->accounts[$i]['ORD']);
        if ($account === null || LoansModule::withdrawalBlock($this->cid, $account, 1) !== null) {
            return;
        }
        $available = SavingsAccounts::available($this->cid, (int) $account->id);
        $amount = $large ? $this->round(min(intdiv($available, 2), 1_200_000 + 100_000 * mt_rand(0, 15))) : $this->round(min(intdiv($available, 3), 50_000 * mt_rand(1, 10)));
        if ($amount < 50_000) {
            return;
        }
        $tx = $this->tx(['kind' => 'withdrawal', 'savings_account_id' => (int) $account->id, 'amount_minor' => $amount, 'channel' => 'cash',
            'narration' => $large ? 'School fees for the term' : null], $this->users['teller']);
        if ($tx !== null && $tx->status === 'pending_approval' && $tx->approval_id) {
            $this->quietly(fn () => app(SaccoApprovals::class)->approve($this->co, (int) $tx->approval_id, $this->users['treasurer'], 'Member present with ID; balance checked.'));
        }
    }

    private function fixedDeposit(int $i, int $amount): void
    {
        if (! $this->isActive($i)) {
            return;
        }
        $acct = $this->quietly(fn () => app(SavingsAccounts::class)->open($this->co, (int) $this->members[$i]->id, $this->savingsProduct('FXD'), $this->users['teller']));
        if ($acct instanceof SaccoSavingsAccount) {
            $this->accounts[$i]['FXD'] = (int) $acct->id;
            $this->tx(['kind' => 'deposit', 'savings_account_id' => (int) $acct->id, 'amount_minor' => $amount, 'channel' => 'bank', 'reference' => 'Fixed deposit for 12 months'], $this->users['teller']);
        }
    }

    /** A fixed deposit that has run its year: moved to the member's ordinary savings. */
    private function fixedMatures(int $i): void
    {
        if (! isset($this->accounts[$i]['FXD'], $this->accounts[$i]['ORD'])) {
            return;
        }
        $balance = (int) SaccoSavingsAccount::withoutGlobalScopes()->whereKey($this->accounts[$i]['FXD'])->value('balance_minor');
        if ($balance > 0) {
            $this->tx(['kind' => 'internal_transfer', 'savings_account_id' => $this->accounts[$i]['FXD'], 'counter_account_id' => $this->accounts[$i]['ORD'], 'amount_minor' => $balance,
                'narration' => 'Fixed deposit matured'], $this->users['teller']);
        }
    }

    private function shareTransfer(int $from, int $to, int $shares, string $reason): void
    {
        if (! $this->isActive($from) || ! $this->isActive($to)) {
            return;
        }
        $this->quietly(function () use ($from, $to, $shares, $reason) {
            $a = app(ShareService::class)->transfer($this->co, (int) $this->members[$from]->id, (int) $this->members[$to]->id, $shares, $reason, $this->users['teller']);
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['treasurer'], 'Transfer form signed by both members.');
        });
    }

    /** A new teacher joins: registered, entrance fee and shares, savings opened, identity checked, admitted. */
    private function join(int $i): void
    {
        $m = $this->data['members'][$i];
        $this->profile[$i] = $this->profileFor($i);
        $manager = $this->users['manager'];
        $member = $this->quietly(fn () => app(MemberService::class)->register($this->co, $this->memberData($i, $m, $this->day()), $manager)['member']);
        if (! $member instanceof SaccoMember) {
            return;
        }
        $this->members[$i] = $member;
        $teller = $this->users['teller'];
        $this->quietly(fn () => app(ShareService::class)->payEntranceFee($this->co, ['member_id' => (int) $member->id, 'channel' => 'cash'], $teller));
        $this->quietly(fn () => app(ShareService::class)->purchase($this->co, ['member_id' => (int) $member->id, 'shares_count' => 5 + $i % 6, 'channel' => 'cash'], $teller));
        foreach (['ORD', 'CMP'] as $code) {
            $acct = $this->quietly(fn () => app(SavingsAccounts::class)->open($this->co, (int) $member->id, $this->savingsProduct($code), $teller));
            if ($acct instanceof SaccoSavingsAccount) {
                $this->accounts[$i][$code] = (int) $acct->id;
            }
        }
        $this->quietly(function () use ($member, $manager) {
            $a = app(SaccoApprovals::class)->request($this->co, 'member_kyc', ['member_id' => (int) $member->id, 'decision' => 'verified'], $manager);
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['secretary'], 'National ID and appointment letter seen.');
        });
        $this->quietly(fn () => app(MemberService::class)->setStatus($this->co, (int) $member->id, 'active', $manager, 'Admitted by the board'));
        if (isset($this->accounts[$i]['ORD'])) {
            $this->tx(['kind' => 'deposit', 'savings_account_id' => $this->accounts[$i]['ORD'], 'amount_minor' => 100_000 * (1 + $i % 4), 'channel' => 'cash', 'narration' => 'Opening deposit'], $teller);
        }
    }

    private function nominate(int $i): void
    {
        if (! isset($this->members[$i])) {
            return;
        }
        $this->quietly(fn () => app(MemberService::class)->nominate($this->co, (int) $this->members[$i]->id, [
            ['name' => 'Daphne Apio Ocen', 'relationship' => 'Daughter', 'phone' => '+256700099101', 'share_bp' => 6000],
            ['name' => 'Ivan Ocen', 'relationship' => 'Son', 'phone' => '+256700099102', 'share_bp' => 4000],
        ], 'Harriet Nakabugo (secretary)', 'Peter Obbo (supervisory board)', $this->day(), $this->users['secretary']));
    }

    private function dies(int $i): void
    {
        if ($this->isActive($i)) {
            $this->quietly(fn () => app(MemberService::class)->setStatus($this->co, (int) $this->members[$i]->id, 'deceased', $this->users['manager'],
                'Died on '.$this->day().'; the savings and shares are held for the nominees named in the register.'));
            $this->profile[$i]['gone_on'] = $this->day();
        }
    }

    /** A member leaves: through the exit settlement (P2.15) when the kernel has it; until then shares move and savings are paid out. */
    private function memberExits(int $i, int $to): void
    {
        if (! $this->isActive($i) || ! $this->isActive($to)) {
            return;
        }
        $member = $this->members[$i];
        if (class_exists(\App\Services\Sacco\Members\MemberExit::class)) {
            $ok = $this->quietly(function () use ($member, $to) {
                $a = (new \App\Services\Sacco\Members\MemberExit())->request($this->co, (int) $member->id, $this->users['manager'], ['reason' => 'voluntary',
                    'note' => 'Retired from teaching and moving to Mbarara.', 'shares_to_member_id' => (int) $this->members[$to]->id, 'channel' => 'bank']);
                app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['treasurer'], 'Exit settlement checked against the statement.');

                return true;
            });
            if ($ok === true) {
                $this->profile[$i]['gone_on'] = $this->day();

                return;
            }
        }
        $shares = ShareService::sharesOf($this->cid, (int) $member->id);
        if ($shares > 0) {
            $this->shareTransfer($i, $to, $shares, 'Exit: shares taken over by another member');
        }
        foreach (['ORD', 'HOL'] as $code) {
            if (isset($this->accounts[$i][$code]) && ($free = SavingsAccounts::available($this->cid, $this->accounts[$i][$code])) > 0) {
                $tx = $this->tx(['kind' => 'withdrawal', 'savings_account_id' => $this->accounts[$i][$code], 'amount_minor' => $free, 'channel' => 'bank', 'narration' => 'Exit: savings paid out'], $this->users['treasurer']);
                if ($tx !== null && $tx->status === 'pending_approval') {
                    $this->quietly(fn () => app(SaccoApprovals::class)->approve($this->co, (int) $tx->approval_id, $this->users['manager'], 'Exit settlement.'));
                }
            }
        }
        $this->quietly(fn () => app(MemberService::class)->setStatus($this->co, (int) $member->id, 'exited', $this->users['manager'], 'Retired from teaching and moving to Mbarara.'));
        $this->profile[$i]['gone_on'] = $this->day();
    }

    private function fine(int $i, int $amount, string $why): void
    {
        if ($this->isActive($i) && isset($this->accounts[$i]['ORD'])) {
            $this->tx(['kind' => 'fee', 'savings_account_id' => $this->accounts[$i]['ORD'], 'amount_minor' => $amount, 'narration' => $why], $this->users['teller']);
        }
    }

    /**
     * A mobile-money deposit pushed twice by the network, reversed by a second officer. Until mobile-money
     * collections exist (P3.1) it is received as a bank-channel receipt carrying the MoMo reference.
     */
    private function momoReversal(int $i): void
    {
        if (! $this->isActive($i) || ! isset($this->accounts[$i]['ORD'])) {
            return;
        }
        $ref = 'MTN MoMo '.sprintf('%010d', 4_100_000_000 + $i * 97);
        $teller = $this->users['teller'];
        $this->tx(['kind' => 'deposit', 'savings_account_id' => $this->accounts[$i]['ORD'], 'amount_minor' => 300_000, 'channel' => 'bank', 'reference' => $ref, 'narration' => 'Mobile money deposit'], $teller);
        $dup = $this->tx(['kind' => 'deposit', 'savings_account_id' => $this->accounts[$i]['ORD'], 'amount_minor' => 300_000, 'channel' => 'bank', 'reference' => $ref.' (repeat)', 'narration' => 'Mobile money deposit'], $teller);
        if ($dup !== null) {
            $this->quietly(function () use ($dup, $teller) {
                $a = app(SaccoTransactions::class)->reverse($this->co, (int) $dup->id, 'The network pushed the same mobile-money payment twice; MTN reversed the second one.', $teller);
                app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['manager'], 'Checked against the MoMo statement.');
            });
        }
    }

    private function placeWithBank(int $amount): void
    {
        $placed = (int) DB::table('sacco_ledger_accounts')->where('company_id', $this->cid)->where('code', '1200')->value('id');
        $lines = [['account_id' => $placed, 'debit_minor' => $amount, 'memo' => 'Fixed deposit with Centenary Bank, 12 months at 11 %'],
            ['account_id' => ChartOfAccounts::id($this->cid, 'bank.main'), 'credit_minor' => $amount, 'memo' => 'Moved from the current account']];
        $this->quietly(function () use ($lines) {
            $a = app(SaccoApprovals::class)->request($this->co, 'manual_journal', ['lines' => $lines, 'memo' => 'Surplus liquidity placed on fixed deposit (board resolution)'], $this->users['treasurer']);
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['manager'], 'Board resolution attached.');
        });
    }

    /** Salaries, the software and the bank: what a SACCO office costs each month (a manual journal, approved). */
    private function operatingCosts(\Carbon\Carbon $month): void
    {
        $acc = fn (string $key) => ChartOfAccounts::id($this->cid, $key);
        $personnel = (int) DB::table('sacco_ledger_accounts')->where('company_id', $this->cid)->where('code', '5300')->value('id');
        $lines = [['account_id' => $personnel, 'debit_minor' => 1_250_000, 'memo' => 'Allowances: manager, loans officer, teller (part-time)'],
            ['account_id' => $acc('exp.software'), 'debit_minor' => 185_000, 'memo' => 'SMS bundle and software'],
            ['account_id' => $acc('exp.bank'), 'debit_minor' => 42_000, 'memo' => 'Bank charges'],
            ['account_id' => $acc('exp.governance'), 'debit_minor' => in_array($month->month, [3, 6, 9, 12], true) ? 600_000 : 0, 'memo' => 'Board meeting allowances']];
        $lines = array_values(array_filter($lines, fn ($l) => $l['debit_minor'] > 0));
        $lines[] = ['account_id' => $acc('bank.main'), 'credit_minor' => array_sum(array_column($lines, 'debit_minor')), 'memo' => 'Paid from the bank'];
        $this->quietly(function () use ($lines, $month) {
            $a = app(SaccoApprovals::class)->request($this->co, 'manual_journal', ['lines' => $lines, 'memo' => 'Office costs for '.$month->format('F Y')], $this->users['manager']);
            app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['treasurer'], 'Payment vouchers attached.');
        });
        // The bank fixed deposit's interest (11 % a year), credited monthly by the bank.
        $placed = (int) DB::table('sacco_ledger_accounts')->where('company_id', $this->cid)->where('code', '1200')->value('id');
        $income = (int) DB::table('sacco_ledger_accounts')->where('company_id', $this->cid)->where('code', '4310')->value('id');
        $held = (int) DB::table('sacco_account_balances')->where('ledger_account_id', $placed)->value('balance_minor');
        if ($held > 0 && $income > 0) {
            $interest = $this->round(intdiv($held * 11, 1200));
            $this->quietly(function () use ($acc, $income, $interest, $month) {
                $a = app(SaccoApprovals::class)->request($this->co, 'manual_journal', ['lines' => [['account_id' => $acc('bank.main'), 'debit_minor' => $interest, 'memo' => 'Credited by the bank'],
                    ['account_id' => $income, 'credit_minor' => $interest, 'memo' => 'Interest on the fixed deposit']], 'memo' => 'Bank deposit interest for '.$month->format('F Y')], $this->users['treasurer']);
                app(SaccoApprovals::class)->approve($this->co, (int) $a->id, $this->users['manager'], 'Agrees to the bank statement.');
            });
        }
    }

    // ── Today: the applications still on their way ────────────────────────

    private function finishingTouches(): void
    {
        $T = $this->today;
        $tell = fn (int $days, int $hour) => $this->at($T->copy()->subDays($days), $hour, 10);
        // Rejected at the committee's sitting (19 days ago), with the minutes.
        $tell(23, 10);
        $rej = $this->freshApplication('rejected', 28, 'ORD', 9_500_000, 24);
        if ($rej !== null) {
            $tell(21, 11);
            $this->appraise('rejected');
            $tell(19, 14);
            $this->quietly(function () use ($rej) {
                (new Committee())->sendToCommittee($this->co, $rej, $this->users['loans']);
                (new Committee())->vote($this->co, $rej, $this->users['committee'], 'reject', 'Minutes: the instalment would take more than half of the payslip; reapply for a smaller amount.');
                (new Committee())->vote($this->co, $rej, $this->users['committee2'], 'reject', 'Minutes: rejected; existing deductions too high.');
            });
        }
        // Approved two days ago within the manager's limit; the member comes in to sign.
        $tell(4, 10);
        if ($this->freshApplication('approved', 32, 'SCH', 3_000_000, 10) !== null) {
            $tell(3, 11);
            $this->appraise('approved');
            $tell(2, 15);
            $this->decide('approved');
        }
        // In committee: appraised yesterday, one vote in so far; the others vote at the next sitting.
        $tell(3, 9);
        $ic = $this->freshApplication('in_committee', 36, 'ORD', 8_000_000, 24);
        if ($ic !== null) {
            $tell(2, 10);
            $this->appraise('in_committee');
            $tell(1, 12);
            $this->quietly(function () use ($ic) {
                (new Committee())->sendToCommittee($this->co, $ic, $this->users['loans']);
                (new Committee())->vote($this->co, $ic, $this->users['committee3'], 'approve', 'Supports it: guarantors strong, land title deposited.');
            });
        }
        // Submitted this morning, waiting for the loans officer's appraisal.
        $tell(0, 8);
        $this->freshApplication('submitted', 39, 'EMG', 1_000_000, 4);

        // The member's own sign-in (/member): the first member, whose books cover every product.
        $this->at($T, 8, 20);
        $login = $this->data['member_login'];
        $who = $this->members[(int) $login['member']];
        (new MemberLogin())->enable($this->co, (int) $who->id, $this->users['manager'], $this->tempEmail('member'), null, Str::password(20, symbols: false));
        if (! isset($this->accounts[(int) $login['member']]['HOL'])) {
            $this->holidayDeposit((int) $login['member']);
        }

        $this->ensureSteps();
    }

    private function freshApplication(string $tag, int $who, string $product, int $amount, int $term): ?int
    {
        $this->scenarios[$tag] = ['tag' => $tag, 'member' => $who, 'product' => $product, 'amount' => $amount, 'term' => $term, 'on' => $this->today->copy(), 'how' => ['pay' => 'none']];
        $this->apply($tag, $this->today);

        return $this->loans[$tag] ?? null;
    }

    // ── Steps that wait for a kernel service ──────────────────────────────

    /**
     * Each part of the brief a kernel service does not do yet, by the class it waits for. Every step is idempotent:
     * it does nothing until its class exists, then adds its part once. @return array<string, string> step => added|done|pending
     */
    public function ensureSteps(): array
    {
        return [
            'ensureYearEnd' => $this->ensureYearEnd(),
            'ensureFines' => $this->ensureFines(),
            'ensureWelfare' => $this->ensureWelfare(),
            'ensureMeeting' => $this->ensureMeeting(),
            'ensureMomo' => $this->ensureMomo(),
        ];
    }

    /** What still waits, for the report and the heal's log. @return list<string> */
    public function pending(): array
    {
        $out = [];
        foreach (self::WAITING as $step => [$classes, $what]) {
            if (! $this->available($classes)) {
                $out[] = "{$step}: {$what}";
            }
        }

        return $out;
    }

    /** step => [the classes it needs (any one), what it adds]. */
    public const WAITING = [
        'ensureYearEnd' => [['App\Services\Sacco\YearEnd\Closing', 'App\Services\Sacco\YearEnd\Dividends'],
            'close the last full financial year (P4.7 appropriation: reserve, education fund) and pay its dividend on shares to savings (P4.8)'],
        'ensureFines' => [['App\Services\Sacco\Group\Fines'], 'fines receivable from the fine catalogue (P5.4); until then office fines are charged to savings as a fee'],
        'ensureWelfare' => [['App\Services\Sacco\Group\Funds'], 'a welfare fund with contributions and one bereavement claim paid from it (P5.5)'],
        'ensureMeeting' => [['App\Services\Sacco\Group\Meetings'], 'the loans committee sitting as a meeting record (P5.2); until then the sitting is the committee\'s votes with their minutes'],
        'ensureMomo' => [['App\Services\Sacco\Payments\SaccoCollections'], 'a mobile-money collection settled by the provider and one reversed (P3.1); until then the reversed MoMo deposit is a bank-channel receipt with the MoMo reference'],
    ];

    /** @param list<string> $classes */
    private function available(array $classes): bool
    {
        foreach ($classes as $class) {
            if (class_exists($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The closed year and its dividend (P4.7, P4.8). Waits for App\Services\Sacco\YearEnd\{Closing, Dividends}; once
     * they exist, the step that uses them goes here (the year before the current one, closed, appropriated and its
     * dividend paid to savings, by the chair's and treasurer's approvals).
     */
    private function ensureYearEnd(): string
    {
        return $this->available(self::WAITING['ensureYearEnd'][0]) ? $this->notWired('ensureYearEnd') : 'pending';
    }

    private function ensureFines(): string
    {
        return $this->available(self::WAITING['ensureFines'][0]) ? $this->notWired('ensureFines') : 'pending';
    }

    private function ensureWelfare(): string
    {
        return $this->available(self::WAITING['ensureWelfare'][0]) ? $this->notWired('ensureWelfare') : 'pending';
    }

    private function ensureMeeting(): string
    {
        return $this->available(self::WAITING['ensureMeeting'][0]) ? $this->notWired('ensureMeeting') : 'pending';
    }

    private function ensureMomo(): string
    {
        return $this->available(self::WAITING['ensureMomo'][0]) ? $this->notWired('ensureMomo') : 'pending';
    }

    /** The service has landed but its demo step is not written yet: logged loudly once per run, never fatal. */
    private function notWired(string $step): string
    {
        Log::warning("[sacco-demo] {$step}: the kernel service it waits for now exists; write the step in SaccoDemoBuilder::{$step}().");

        return 'pending';
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** The SACCO's calendar day now (the simulated clock), as Y-m-d. */
    private function day(): string
    {
        return \App\Support\LocalDate::today($this->cid)->toDateString();
    }

    /** @return array<string, mixed> what the heal needs */
    private function snapshot(): array
    {
        return ['build' => self::BUILD, 'users' => array_map(fn (User $u) => (int) $u->id, $this->users), 'loans' => $this->loans, 'payers' => array_values(array_unique($this->payers)),
            'members' => array_map(fn (SaccoMember $m) => (int) $m->id, $this->members), 'member_login' => (int) $this->members[(int) $this->data['member_login']['member']]->id,
            'started_on' => $this->start->toDateString(), 'skipped' => count($this->skipped)];
    }

    private function workingDayOnOrBefore(\Carbon\Carbon $day): \Carbon\Carbon
    {
        $d = $day->copy()->setTimezone($this->tz)->startOfDay();
        while ($d->isWeekend()) {
            $d->subDay();
        }

        return $d;
    }

    /** Move the clock to a moment of a day (never past the real now). */
    private function at(\Carbon\Carbon $day, int $hour, int $minute = 0): \Carbon\Carbon
    {
        $t = Carbon::create($day->year, $day->month, $day->day, $hour, $minute, 0, $this->tz);
        if ($t->gt($this->realNow)) {
            $t = $this->realNow->copy()->subSeconds(30);
        }
        Carbon::setTestNow($t);

        return $t;
    }

    /** One refused sample entry (a rule that bites on sample data) must not stop the rest. */
    private function quietly(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (BusinessRuleException|\DomainException|\InvalidArgumentException $e) {
            $this->skipped[] = now()->toDateString().': '.$e->getMessage();
            Log::info('[sacco-demo] sample entry skipped: '.$e->getMessage());

            return null;
        }
    }

    /** @return array<string, mixed> */
    private function actAs(User $user): array
    {
        $prev = [];
        foreach (['web', 'admin'] as $name) {
            try {
                $guard = Auth::guard($name);
                $prev[$name] = $guard->hasUser() ? $guard->user() : null;
                $guard->setUser($user);
            } catch (\Throwable) {
                // guard not configured in this app
            }
        }

        return $prev;
    }

    /** @param array<string, mixed> $prev */
    private function restore(array $prev): void
    {
        foreach ($prev as $name => $user) {
            $guard = Auth::guard($name);
            if ($user !== null) {
                $guard->setUser($user);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }
}

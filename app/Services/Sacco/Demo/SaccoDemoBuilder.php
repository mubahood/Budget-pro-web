<?php

namespace App\Services\Sacco\Demo;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Plan;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoMember;
use App\Models\SaccoPeriod;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Onboarding\OnboardingEvents;
use App\Services\Onboarding\OnboardingService;
use App\Services\Sacco\Ledger\OpeningBalances;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Loans\Committee;
use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Loans\LoansModule;
use App\Services\Sacco\Loans\Repayments;
use App\Services\Sacco\Members\MemberLogin;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoSetupService;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use App\Services\Sacco\Shares\ShareService;
use App\Services\Sacco\Simple\LoanFlow;
use App\Services\Sacco\Simple\Money;
use App\Services\Sacco\Simple\SaccoAccount;
use App\Services\Sacco\Simple\ShareOut;
use App\Services\Team\TeamService;
use Database\Seeders\AkibaPlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fills the Akiba Ledger demo (SaccoDemo, AK-35): "Wakiso Teachers' SACCO (Demo)", office mode, 60 fictional teachers
 * and `demo.sacco.months` of books, every shilling posted through the SACCO's own services (no row is written into a
 * ledger table by hand), so the trial balance, the sub-ledgers and LedgerVerifier agree.
 *
 * The clock runs forward day by day from the first of the month `months` ago: the SACCO moves its books in with
 * opening balances on day one, then each day's work happens at its own time (payroll check-off, counter deposits,
 * shares bought by number at the SACCO's price, withdrawals, the SACCO's own money in and out), the loan book's run
 * catches up before each day's work, and each past month is closed on the 2nd. So today's arrears are true.
 *
 * It is a simple SACCO (docs/akiba/flows-plan.md): loans go through LoanFlow (the member asks with one guarantor,
 * the guarantor answers, the chairperson decides, the treasurer pays out or not), at 1 % a month on the reducing
 * balance (one loan flat), with every simple status present: waiting for the guarantor (the guarantor's own sign-in
 * has it), waiting for the chairperson, approved for the treasurer to pay out, running (some late), closed, declined
 * by the guarantor, declined by the chairperson and not paid out. The SACCO account has about a dozen entries and a
 * reversed mistake; one share-out ran at the end of the last full year. Beside them: savings in each product, a share
 * transfer, an exited member, a deceased member with nominees, office fines charged as fees and a reversed MoMo receipt.
 *
 * The fines list and the welfare fund wait for their kernel services (ensureFines, ensureWelfare: pending()).
 */
final class SaccoDemoBuilder
{
    /**
     * Bumped when a build changes what the demo holds. A live demo built by an older version is rebuilt on the next
     * run, not healed.
     */
    public const BUILD = 2;

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

    /** @var array<string, list<array{t: int, fn: callable}>> Y-m-d => the day's work */
    private array $calendar = [];

    /** @var array<string, array<string, mixed>> scenario tag => what it is and how it pays */
    private array $scenarios = [];

    /** @var array<string, int> scenario tag => loan id */
    private array $loans = [];

    /** @var list<int> loan ids repaid on time from their due dates (the heal keeps them so) */
    private array $payers = [];

    /** The SACCO-account entry entered twice (reversed the day after). */
    private int $accountMistake = 0;

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
                        $this->monthEnd();
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

        // Loans: the simple flow's member loan, at 1 % a month on the reducing balance (Settings › Loan interest).
        (new LoanFlow())->product($this->cid);
        (new LoanFlow())->updateSettings($this->co, $chair, ['rate_bp' => 100, 'rate_period' => 'month', 'method' => 'reducing']);
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
            'nin' => self::nin($gender, $year, $i), 'phone' => sprintf('+2567000%05d', 10_101 + $i * 7), 'email' => in_array($i, array_map(fn ($l) => (int) $l['member'], $this->data['member_logins']), true) ? null
                : strtolower($first.'.'.$last).'@'.$this->data['sacco']['domain'], 'district' => 'Wakiso', 'subcounty' => $counties[$i % count($counties)],
            'occupation' => 'Teacher', 'employer_name' => $this->profile[$i]['school'], 'payroll_no' => sprintf('TCH-%05d', 31_000 + $i * 13),
            'joined_on' => $joined, 'consent_data' => true, 'consent_messages' => true, 'consent_credit_bureau' => $i % 3 !== 0];
    }

    /** A clearly fictional NIN in the card's shape: CM (men) or CF (women), the birth year, "DEMO", a number. */
    public static function nin(string $gender, int $year, int $i): string
    {
        return ($gender === 'male' ? 'CM' : 'CF').substr((string) $year, -2).'DEMO'.sprintf('%04d', $i + 1).chr(65 + $i % 26).chr(65 + ($i * 7) % 26);
    }

    // ── The plan: who borrows what, and what happens to each request ──────

    private function plan(): void
    {
        $T = $this->today;
        $ago = fn (int $months, int $days = 0) => $T->copy()->subMonthsNoOverflow($months)->subDays($days)->startOfDay();

        // Loan requests through the simple flow (LoanFlow): [tag, borrower, guarantor, amount, months, asked on, what happens].
        // The guarantor answers a day later, the chairperson two days after that, the treasurer pays out two days later.
        $plan = [
            // Paid out and repaid on time (one at a flat rate).
            ['running_1', 0, 5, 4_000_000, 12, $ago(6, 5), ['pay' => 'all']],
            ['running_2', 4, 8, 8_000_000, 24, $ago(10, 2), ['pay' => 'all']],
            ['running_flat', 10, 12, 2_500_000, 10, $ago(3, 11), ['pay' => 'all', 'flat' => true]],
            ['running_4', 20, 21, 6_000_000, 18, $ago(8, 4), ['pay' => 'all']],
            ['running_5', 25, 26, 1_500_000, 6, $ago(2, 9), ['pay' => 'all']],
            ['running_6', 30, 31, 5_000_000, 12, $ago(4, 16), ['pay' => 'all']],
            ['running_7', 35, 36, 3_000_000, 12, $ago(1, 12), ['pay' => 'all']],
            // Paid out and behind: the oldest unpaid instalment about 17, 42 and 100 days old.
            ['late_17', 3, 9, 3_000_000, 12, $ago(7, 22), ['pay' => 'until', 'missed' => 1]],
            ['late_42', 7, 13, 2_200_000, 10, $ago(6, 17), ['pay' => 'until', 'missed' => 2]],
            ['late_100', 11, 14, 3_500_000, 18, $ago(9, 13), ['pay' => 'until', 'missed' => 4]],
            // Fully paid (one settled early, with no fee).
            ['closed_1', 2, 6, 1_500_000, 6, $ago(15, 3), ['pay' => 'all']],
            ['closed_2', 1, 15, 2_000_000, 8, $ago(14, 20), ['pay' => 'all']],
            ['closed_early', 12, 16, 3_000_000, 12, $ago(11, 7), ['pay' => 'all', 'settle_after' => 5]],
            // Declined by the guarantor; declined by the chairperson; approved but not paid out.
            ['declined_guarantor', 17, 18, 2_000_000, 12, $ago(2, 3), ['guarantor' => 'decline', 'note' => 'I already guarantee a colleague\'s loan this year.']],
            ['declined_chair', 19, 22, 12_000_000, 24, $ago(1, 4), ['chair' => 'decline', 'note' => 'The instalment would take more than half of the payslip. Ask again for a smaller amount.']],
            ['not_paid_out', 23, 24, 1_800_000, 6, $ago(0, 25), ['not_paid' => 'The member withdrew the request before it was paid out.']],
            // Waiting today: the treasurer to pay out, the chairperson to decide, the guarantor's login to answer.
            ['approved', 27, 28, 2_400_000, 12, $ago(0, 4), ['stop' => 'approved']],
            ['pending_chair', 29, 32, 3_200_000, 12, $ago(0, 3), ['stop' => 'pending_chair']],
            ['pending_guarantor', 33, 1, 1_200_000, 6, $ago(0, 1), ['stop' => 'pending_guarantor']],
        ];
        foreach ($plan as [$tag, $who, $guarantor, $amount, $months, $on, $how]) {
            $this->scenarios[$tag] = compact('tag', 'who', 'guarantor', 'amount', 'months', 'on', 'how');
            $this->scheduleLoan($tag);
        }

        $this->routine();
        $this->lifeEvents();
        $this->accountEntries();
        $this->shareOut();
    }

    /** The request and each person's step, on their days. */
    private function scheduleLoan(string $tag): void
    {
        $s = $this->scenarios[$tag];
        $on = $s['on'];
        $how = $s['how'];
        $this->on($on, 19, fn () => $this->askForLoan($tag)); // a teacher asks in the evening, from the member page
        if (($how['stop'] ?? null) === 'pending_guarantor') {
            return;
        }
        $this->on($on->copy()->addDay(), 18, fn () => $this->guarantorAnswers($tag));
        if (isset($how['guarantor']) || ($how['stop'] ?? null) === 'pending_chair') {
            return;
        }
        $this->on($on->copy()->addDays(3), 12, fn () => $this->chairDecides($tag));
        if (isset($how['chair']) || ($how['stop'] ?? null) === 'approved') {
            return;
        }
        $this->on($on->copy()->addDays(5), 11, fn () => isset($how['not_paid']) ? $this->notPaidOut($tag) : $this->payOut($tag));
    }

    /** The SACCO's every month: payroll check-off, the counter, shares, withdrawals. */
    private function routine(): void
    {
        for ($m = $this->start->copy(); $m->lte($this->today); $m->addMonthNoOverflow()) {
            $month = $m->copy();
            $payday = $this->workingDayOnOrBefore($month->copy()->day(min(27, $month->daysInMonth)));
            if ($payday->lte($this->today)) {
                $this->on($payday, 9, fn () => $this->checkOff($month));
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
        foreach (range(48, 59) as $n => $i) {
            $day = $this->workingDayOnOrBefore($this->start->copy()->addMonthsNoOverflow(1 + $n)->addDays(8 + ($n * 3) % 15));
            if ($day->lt($T)) {
                $this->on($day, 9, fn () => $this->join($i));
            }
        }
        foreach ([[17, 3, 5_000_000], [21, 6, 3_000_000], [27, 10, 8_000_000], [33, 2, 2_000_000], [40, 12, 4_000_000], [0, 13, 1_500_000]] as [$who, $monthsIn, $amount]) {
            $this->on($this->workingDayOnOrBefore($this->start->copy()->addMonthsNoOverflow($monthsIn)->addDays(4)), 12, fn () => $this->fixedDeposit($who, $amount));
        }
        $this->on($this->workingDayOnOrBefore($T->copy()->subMonthsNoOverflow(9)->day(14)), 11, fn () => $this->shareTransfer(26, 29, 15, 'Sold to a colleague at the share price'));
        $this->on($this->workingDayOnOrBefore($T->copy()->subMonthsNoOverflow(3)->day(10)), 11, fn () => $this->memberExits(47, 46));
        $this->on($this->workingDayOnOrBefore($this->start->copy()->addMonthsNoOverflow(2)->day(12)), 12, fn () => $this->nominate(34));
        $this->on($this->workingDayOnOrBefore($T->copy()->subMonthsNoOverflow(4)->day(18)), 12, fn () => $this->dies(34));
        $agm = $this->workingDayOnOrBefore(Carbon::create($this->start->year + 1, 3, 28, 0, 0, 0, $this->tz));
        if ($agm->lt($T)) {
            foreach ([23, 31, 38, 42, 44] as $who) {
                $this->on($agm->copy()->addDays(3), 13, fn () => $this->fine($who, 20_000, 'Fine: absent from the annual general meeting without apology'));
            }
        }
        $this->on($this->workingDayOnOrBefore($T->copy()->subMonthsNoOverflow(1)->day(5)), 13, fn () => $this->fine(3, 15_000, 'Fine: loan instalment paid late'));
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
                $this->monthEnd();
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

    /**
     * The month-end work the loan book's run does only when a loan is open (savings interest, P4.6): in the first
     * months, before any loan, it is run here, so every month can close. Idempotent per month.
     */
    private function monthEnd(): void
    {
        if (class_exists(\App\Services\Sacco\YearEnd\SavingsInterest::class) && \App\Services\Sacco\YearEnd\YearEndModule::ready()) {
            $this->quietly(fn () => (new \App\Services\Sacco\YearEnd\SavingsInterest())->catchUp($this->cid));
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

    // ── Loans: the simple flow ────────────────────────────────────────────

    private function askForLoan(string $tag): void
    {
        $s = $this->scenarios[$tag];
        $member = $this->members[$s['who']] ?? null;
        $guarantor = $this->members[$s['guarantor']] ?? null;
        if ($member === null || $guarantor === null) {
            return;
        }
        $flow = new LoanFlow();
        $flat = ! empty($s['how']['flat']);
        if ($flat) {
            $this->quietly(fn () => $flow->updateSettings($this->co, $this->users['chair'], ['method' => 'flat']));
        }
        $purposes = $this->data['purposes'];
        $loan = $this->quietly(fn () => $flow->request($this->co, $member->fresh(), ['amount_minor' => $s['amount'], 'months' => $s['months'],
            'purpose' => $purposes[crc32($tag) % count($purposes)], 'guarantor_member_id' => (int) $guarantor->id]));
        if ($flat) {
            $this->quietly(fn () => $flow->updateSettings($this->co, $this->users['chair'], ['method' => 'reducing']));
        }
        if ($loan instanceof SaccoLoan) {
            $this->loans[$tag] = (int) $loan->id;
        }
    }

    private function guarantorAnswers(string $tag): void
    {
        $s = $this->scenarios[$tag];
        if (! isset($this->loans[$tag])) {
            return;
        }
        $decline = ($s['how']['guarantor'] ?? null) === 'decline';
        $this->quietly(fn () => (new LoanFlow())->guarantorDecide($this->co, $this->members[$s['guarantor']]->fresh(), $this->loans[$tag], $decline ? 'decline' : 'approve',
            $decline ? $s['how']['note'] : 'I know the borrower well; we teach at the same school.'));
    }

    private function chairDecides(string $tag): void
    {
        $s = $this->scenarios[$tag];
        if (! isset($this->loans[$tag])) {
            return;
        }
        $decline = ($s['how']['chair'] ?? null) === 'decline';
        $this->quietly(fn () => (new LoanFlow())->chairDecide($this->co, $this->users['chair'], $this->loans[$tag], $decline ? 'decline' : 'approve',
            $decline ? $s['how']['note'] : 'Savings and payslip seen; approved.'));
    }

    private function payOut(string $tag): void
    {
        $id = $this->loans[$tag] ?? null;
        if ($id === null) {
            return;
        }
        $loan = $this->quietly(fn () => (new LoanFlow())->payOut($this->co, $this->users['treasurer'], $id, ['method' => 'bank', 'notes' => 'Transferred to the member\'s salary account']));
        if ($loan instanceof SaccoLoan && $loan->status === 'active') {
            $this->afterPaidOut($tag, $id);
        }
    }

    private function notPaidOut(string $tag): void
    {
        $id = $this->loans[$tag] ?? null;
        if ($id !== null) {
            $this->quietly(fn () => (new LoanFlow())->notPaidOut($this->co, $this->users['treasurer'], $id, (string) $this->scenarios[$tag]['how']['not_paid']));
        }
    }

    /** On-time borrowers join the payers' list; the late ones pay until they stop; one settles early. */
    private function afterPaidOut(string $tag, int $id): void
    {
        $how = $this->scenarios[$tag]['how'];
        $lines = SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $id)->orderBy('installment_no')->get();
        if ($how['pay'] === 'all') {
            $this->payers[] = $id;
            if (isset($how['settle_after']) && ($line = $lines[$how['settle_after'] - 1] ?? null) !== null) {
                $this->on($line->due_on->copy()->addDays(9), 11, fn () => $this->settle($id));
            }

            return;
        }
        $due = $lines->filter(fn ($l) => $l->due_on->lte($this->today))->values();
        foreach ($due->take(max(0, $due->count() - (int) $how['missed'])) as $l) {
            $this->on($l->due_on, 16, fn () => $this->payDue($id, $l->due_on->copy()));
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

        return $this->quietly(fn () => (new LoanFlow())->repay($this->co, $this->users['teller'], $loanId, ['amount_minor' => $amount, 'paid_on' => $day->toDateString(), 'method' => 'bank'])) !== null;
    }

    /** Everything owed today, in one payment (no early-settlement fee). */
    private function settle(int $id): void
    {
        $this->quietly(function () use ($id) {
            $q = (new Repayments())->quote($this->cid, $id);
            (new LoanFlow())->repay($this->co, $this->users['teller'], $id, ['amount_minor' => (int) $q['total_minor'], 'method' => 'bank']);
        });
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
            if ($this->profile[$i]['ord'] > 0 && isset($this->accounts[$i]['ORD'])) {
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
        if (! $this->isActive($i) || ! isset($this->accounts[$i]['ORD'])) {
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
        if ($this->isActive($i) && isset($this->accounts[$i]['ORD']) && SavingsAccounts::available($this->cid, $this->accounts[$i]['ORD']) >= $amount) {
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

    // ── The SACCO's own account and the share-out ─────────────────────────

    /** About a dozen of the SACCO's own entries over the months, and one mistake put right by a reversal. */
    private function accountEntries(): void
    {
        $T = $this->today;
        $at = fn (int $monthsIn, int $day) => $this->workingDayOnOrBefore($this->start->copy()->addMonthsNoOverflow($monthsIn)->day($day));
        $entries = [
            [$at(0, 8), 'out', 'Office expenses', 350_000, 'cash', 'Receipt books and stationery'],
            [$at(2, 15), 'in', 'Grant', 5_000_000, 'bank', 'Grant from the district cooperative office for a safe and a laptop'],
            [$at(2, 20), 'out', 'Office expenses', 2_900_000, 'bank', 'Laptop and a fire-proof safe'],
            [$at(3, 28), 'out', 'Bank charges', 45_000, 'bank', 'Quarterly ledger fees'],
            [$at(6, 28), 'out', 'Bank charges', 45_000, 'bank', 'Quarterly ledger fees'],
            [$at(7, 12), 'in', 'Other income', 120_000, 'cash', 'Sale of old office chairs'],
            [$at(9, 28), 'out', 'Bank charges', 45_000, 'bank', 'Quarterly ledger fees'],
            [$at(11, 30), 'out', 'Meetings', 600_000, 'cash', 'Annual general meeting: hall and refreshments'],
            [$at(12, 28), 'out', 'Bank charges', 45_000, 'bank', 'Quarterly ledger fees'],
            [$at(13, 10), 'in', 'Other income', 250_000, 'bank', 'Interest on the bank account'],
            [$at(15, 28), 'out', 'Bank charges', 45_000, 'bank', 'Quarterly ledger fees'],
            [$at(16, 6), 'out', 'Office expenses', 180_000, 'cash', 'Printer toner'],
        ];
        foreach ($entries as [$day, $direction, $category, $amount, $method, $notes]) {
            if ($day->lt($T)) {
                $this->on($day, 14, fn () => $this->accountEntry($direction, $category, $amount, $method, $notes));
            }
        }
        // The toner entered twice: the second entry reversed the next day, never edited.
        $twice = $at(16, 6);
        if ($twice->copy()->addDays(3)->lt($T)) {
            $this->on($twice, 15, function () {
                $e = $this->accountEntry('out', 'Office expenses', 180_000, 'cash', 'Printer toner');
                if ($e !== null) {
                    $this->accountMistake = (int) $e->id;
                }
            });
            $this->on($twice->copy()->addDays(3), 10, fn () => $this->accountMistake > 0
                ? $this->quietly(fn () => (new SaccoAccount())->reverse($this->co, $this->users['treasurer'], $this->accountMistake, 'Entered twice: the toner was bought once.'))
                : null);
        }
    }

    private function accountEntry(string $direction, string $category, int $amount, string $method, string $notes): ?\App\Models\SaccoAccountEntry
    {
        $svc = new SaccoAccount();
        $cat = collect($svc->categories($this->cid))->first(fn ($c) => $c->direction === $direction && $c->name === $category);
        if ($cat === null) {
            return null;
        }
        $r = $this->quietly(fn () => $svc->record($this->co, $this->users['treasurer'], ['direction' => $direction, 'category_id' => (int) $cat->id, 'amount_minor' => $amount,
            'method' => $method, 'notes' => $notes, 'value_date' => $this->day()]));

        return $r['entry'] ?? null;
    }

    /** The share-out at the end of the last full year: savings back and the year's profit by shares, posted and locked. */
    private function shareOut(): void
    {
        $day = $this->workingDayOnOrBefore(Carbon::create($this->today->year - 1, 12, 31, 0, 0, 0, $this->tz));
        if ($day->lt($this->start) || $day->gte($this->today)) {
            return;
        }
        $this->on($day, 16, function () {
            $svc = new ShareOut();
            $pool = (int) $svc->preview($this->cid)['pool_minor'];
            $amount = min($pool, Money::available($this->cid, 'bank'));
            if ($amount > 0) {
                $this->quietly(fn () => $svc->run($this->co, $this->users['treasurer'], ['amount_minor' => $amount, 'method' => 'bank', 'share_on' => $this->day(),
                    'notes' => 'Year-end share-out agreed at the general meeting: savings returned and the profit shared by shares.']));
            }
        });
    }

    // ── Today: the member sign-ins ─────────────────────────────────────────

    private function finishingTouches(): void
    {
        $this->at($this->today, 8, 20);
        foreach ($this->data['member_logins'] as $key => $login) {
            $who = $this->members[(int) $login['member']];
            (new MemberLogin())->enable($this->co, (int) $who->id, $this->users['manager'], $this->tempEmail($key), null, Str::password(20, symbols: false));
        }
        if (! isset($this->accounts[0]['HOL'])) {
            $this->holidayDeposit(0);
        }
        $this->ensureSteps();
    }

    // ── Steps that wait for a kernel service ──────────────────────────────

    /** Each simple part the kernel does not do yet, by the class it waits for. @return array<string, string> step => added|pending */
    public function ensureSteps(): array
    {
        return ['ensureFines' => $this->ensureFines(), 'ensureWelfare' => $this->ensureWelfare()];
    }

    /** What still waits, for the report and the heal's log. @return list<string> */
    public function pending(): array
    {
        $out = [];
        foreach (self::WAITING as $step => [$classes, $what]) {
            // Pending until the step is written, whether or not its service has landed yet.
            $out[] = $this->available($classes) ? "{$step} (its service exists; write the step): {$what}" : "{$step}: {$what}";
        }

        return $out;
    }

    /** step => [the classes it needs (any one), what it adds]. */
    public const WAITING = [
        'ensureFines' => [['App\Services\Sacco\Group\Fines'], 'fines owed from a fine list (P5.4); until then office fines are charged to savings as a fee'],
        'ensureWelfare' => [['App\Services\Sacco\Group\Funds'], 'a welfare fund with contributions and one bereavement claim paid from it (P5.5)'],
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

    private function ensureFines(): string
    {
        return $this->available(self::WAITING['ensureFines'][0]) ? $this->notWired('ensureFines') : 'pending';
    }

    private function ensureWelfare(): string
    {
        return $this->available(self::WAITING['ensureWelfare'][0]) ? $this->notWired('ensureWelfare') : 'pending';
    }

    /** The service has landed but its demo step is not written yet: logged, never fatal. */
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
            'members' => array_map(fn (SaccoMember $m) => (int) $m->id, $this->members), 'member_logins' => array_map(fn ($l) => (int) $this->members[(int) $l['member']]->id, $this->data['member_logins']),
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

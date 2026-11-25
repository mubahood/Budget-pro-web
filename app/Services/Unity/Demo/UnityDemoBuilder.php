<?php

namespace App\Services\Unity\Demo;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Plan;
use App\Models\SaccoMember;
use App\Models\Subscription;
use App\Models\UnityCategory;
use App\Models\UnityChapter;
use App\Models\User;
use App\Services\Onboarding\OnboardingEvents;
use App\Services\Onboarding\OnboardingService;
use App\Services\Team\TeamService;
use App\Services\Unity\Memberships;
use Database\Seeders\UnityDeskPlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Builds the demo association: "Sample Association (Demo)", 80 invented members in four chapters on three categories
 * (Ordinary annual, Student annual, Life). Most are paid up, some have lapsed, some end within the month, a few have
 * paid part, a few are waived, and the newest have paid nothing yet. Every name is made up, no phone numbers, and the
 * same every build (no randomness: the counters below pick from the lists). Dates are counted back from today.
 */
final class UnityDemoBuilder
{
    /** Bump when what is built changes: the next run rebuilds. */
    public const BUILD = 1;

    public const MEMBERS = 80;

    /** The sign-ins: key => [name, team role]. The chair is the account's owner. Each is also a member of the association. */
    public const LOGINS = [
        'chair' => ['name' => 'Agnes Nakato', 'role' => 'owner', 'label' => 'Chair'],
        'secretary' => ['name' => 'Moses Okiror', 'role' => 'unity_secretary', 'label' => 'Secretary'],
        'treasurer' => ['name' => 'Harriet Achieng', 'role' => 'unity_treasurer', 'label' => 'Treasurer'],
        'lead' => ['name' => 'Samuel Kiggundu', 'role' => 'unity_chapter_lead', 'label' => 'Chapter lead'],
        'member' => ['name' => 'Juliet Auma', 'role' => 'unity_member', 'label' => 'Member'],
    ];

    private const CHAPTERS = [['Central', 'Sample City'], ['Northern', 'North Town'], ['Eastern', 'East Town'], ['Western', 'West Town']];

    private const MALE = ['Joel', 'Paul', 'Isaac', 'Timothy', 'Gideon', 'Silas', 'Daniel', 'Caleb', 'Nathan', 'Edwin', 'Allan', 'Derrick', 'Ivan', 'Ronald', 'Elias', 'Peter', 'Simon', 'Martin', 'Brian', 'Henry'];

    private const FEMALE = ['Ruth', 'Lydia', 'Naomi', 'Dorcas', 'Miriam', 'Agatha', 'Grace', 'Joy', 'Faith', 'Mercy', 'Hope', 'Doreen', 'Brenda', 'Irene', 'Martha', 'Gloria', 'Peace', 'Ann', 'Rhoda', 'Esther'];

    private const SURNAMES = ['Akello', 'Nankunda', 'Kamara', 'Odongo', 'Atim', 'Mugisha', 'Asiimwe', 'Okello', 'Kyomugisha', 'Ainembabazi', 'Byamukama', 'Namara', 'Wasswa', 'Nabirye', 'Opio', 'Tumusiime'];

    private const OCCUPATIONS = ['Teacher', 'Nurse', 'Trader', 'Farmer', 'Accountant', 'Mechanic', 'Tailor', 'Student', 'Engineer', 'Driver'];

    /** What each member's books look like, read in turn (one letter per member): see grow(). */
    private const PATTERN = 'GGGLGEGGPGGLGGWGEGGNGGLGGGEGLGGPGGGLGGEGGWGGNGLGGGEGGLPGGGGLGEGGGNGGLGWGGEGGGLNG';

    public ?int $companyId = null;

    private Company $co;

    /** @var array<string, User> */
    private array $users = [];

    private string $token;

    /** @var array<string, UnityCategory> */
    private array $cats = [];

    /** @return array{company: Company, users: array<string, User>, snapshot: array<string, mixed>} */
    public function build(): array
    {
        $this->token = strtolower(Str::random(6));
        UnityDemo::$building = true;
        try {
            OnboardingEvents::muted(function () {
                $this->createCompany();
                $this->createTeam();
                $this->grow();
            });
        } finally {
            UnityDemo::$building = false;
        }

        return ['company' => $this->co->fresh(), 'users' => array_map(fn (User $u) => $u->fresh(), $this->users),
            'snapshot' => ['build' => self::BUILD, 'users' => array_map(fn (User $u) => (int) $u->id, $this->users),
                'members' => SaccoMember::withoutGlobalScopes()->where('company_id', $this->companyId)->count()]];
    }

    private function createCompany(): void
    {
        $owner = $this->newLogin('chair');
        $co = new Company();
        $co->owner_id = $owner->id;
        $co->name = (string) config('unity.demo.company_name', 'Sample Association (Demo)');
        $co->status = 'Active';
        $co->is_demo = true;
        $co->demo_parent_id = null;
        $co->currency = 'UGX';
        $co->country = 'UG';
        $co->timezone = 'Africa/Kampala';
        $co->business_type = 'unity';
        $co->email = 'office@'.UnityDemo::domain();
        $co->license_expire = now()->addYears(5);
        $co->enabled_modules = ['unity'];
        $co->receipt_channels = [];
        $co->onboarding_state = ['step' => 'done', 'completed_steps' => config('onboarding.steps'), 'skipped_steps' => [], 'completed_at' => now()->toIso8601String(),
            'dismissed_checklist' => true, 'demo' => true, 'version' => OnboardingService::STATE_VERSION, UnityDemo::MARK => ['building' => true]];
        $co->save();
        $this->co = $co->fresh();
        $this->companyId = (int) $co->id;

        CompanyMember::create(['company_id' => $co->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $owner->refresh();
        User::ensureCompanyOwnerRole($owner);
        $this->users['chair'] = $owner->fresh();

        $plan = (string) config('unity.demo.plan', 'unitydesk-organisation');
        if (! Plan::where('slug', $plan)->exists()) {
            (new UnityDeskPlanSeeder())->run();
        }
        Subscription::create(['company_id' => $co->id, 'plan_id' => Plan::where('slug', $plan)->value('id'), 'status' => 'active', 'provider' => 'demo',
            'starts_at' => now(), 'ends_at' => now()->addYears(5)]);
        $this->co = $this->co->fresh();
    }

    private function createTeam(): void
    {
        foreach (self::LOGINS as $key => $l) {
            if ($l['role'] === 'owner') {
                continue;
            }
            $this->users[$key] = app(TeamService::class)->createMember($this->co, $this->users['chair'], $l['name'], $this->tempEmail($key), null, Str::password(24, symbols: false), $l['role']);
        }
    }

    /** A sign-in with a temporary address (the published one is given at hand-over) and a random password for now. */
    private function newLogin(string $key): User
    {
        [$first, $last] = explode(' ', self::LOGINS[$key]['name'], 2) + [1 => ''];
        $u = new User();
        $u->first_name = $first;
        $u->last_name = $last;
        $u->name = self::LOGINS[$key]['name'];
        $u->email = $this->tempEmail($key);
        $u->username = $u->email;
        $u->password = Hash::make(Str::random(40));
        $u->status = 'Active';
        $u->save();

        return $u;
    }

    private function tempEmail(string $key): string
    {
        return "{$key}+build-{$this->token}@".UnityDemo::domain();
    }

    // ── The association ────────────────────────────────────────────────

    /**
     * Five officers first (their member records carry the published sign-in address, so "my membership" finds them), then
     * the rest. Pattern letters: G paid and good, L lapsed (a period that ended months ago), E ends within 30 days,
     * P part paid (pending, so not in force yet), W waived, N new with nothing paid. Every 13th member is a life member.
     */
    private function grow(): void
    {
        $cid = $this->companyId;
        $today = Carbon::parse(\App\Support\LocalDate::today($cid)->toDateString());
        $this->cats = [
            'ordinary' => UnityCategory::create(['company_id' => $cid, 'name' => 'Ordinary annual', 'fee_minor' => 50000, 'period_months' => 12]),
            'student' => UnityCategory::create(['company_id' => $cid, 'name' => 'Student annual', 'fee_minor' => 20000, 'period_months' => 12]),
            'life' => UnityCategory::create(['company_id' => $cid, 'name' => 'Life', 'fee_minor' => 500000, 'period_months' => 0]),
        ];

        $members = [];
        $logins = array_keys(self::LOGINS);
        for ($i = 0; $i < self::MEMBERS; $i++) {
            $members[] = $this->member($i, $logins[$i] ?? null, $today);
        }

        // Four chapters, each led by a member; the chapter lead's sign-in leads Central.
        $chapters = [];
        foreach (self::CHAPTERS as $c => [$name, $place]) {
            $lead = $c === 0 ? $members[3] : $members[10 + $c * 7];
            $chapters[] = UnityChapter::create(['company_id' => $cid, 'name' => $name, 'location' => $place, 'lead_member_id' => $lead->id]);
        }
        foreach ($members as $i => $m) {
            DB::table('sacco_members')->where('id', $m->id)->update(['chapter_id' => $chapters[$i % 4]->id]);
        }

        foreach ($members as $i => $m) {
            $this->books($m, $i, self::PATTERN[$i] ?? 'G', $today);
        }
        Memberships::recomputeAll($cid);
    }

    private function member(int $i, ?string $login, Carbon $today): SaccoMember
    {
        $female = $i % 2 === 0;
        if ($login !== null) {
            [$first, $last] = explode(' ', self::LOGINS[$login]['name'], 2);
            $female = in_array($login, ['chair', 'treasurer', 'member'], true);
        } else {
            $first = ($female ? self::FEMALE : self::MALE)[($i * 7) % 20];
            $last = self::SURNAMES[($i * 5 + intdiv($i, 16)) % count(self::SURNAMES)];
        }
        $since = $today->copy()->subDays(40 + (($i * 97) % 3000)); // joined from about six weeks to eight years ago
        $m = new SaccoMember(['company_id' => $this->companyId, 'kind' => 'individual', 'first_name' => $first, 'last_name' => $last,
            'gender' => $female ? 'female' : 'male', 'occupation' => self::OCCUPATIONS[$i % count(self::OCCUPATIONS)],
            'email' => $login !== null ? UnityDemo::email($login) : null, 'member_since' => $since->toDateString(),
            'created_by_id' => $this->users['chair']->id]);
        $m->save();

        return $m->fresh();
    }

    /** A member's periods. Life every 13th; students are every 6th (a smaller fee). */
    private function books(SaccoMember $m, int $i, string $kind, Carbon $today): void
    {
        $by = (int) $this->users['treasurer']->id;
        $method = ['cash', 'mobile_money', 'bank', 'mobile_money'][$i % 4];
        if ($i % 13 === 12) {
            $paid = $today->copy()->subDays(100 + $i * 11);
            Memberships::renew($m, $this->cats['life'], ['starts_on' => $paid->toDateString(), 'paid_minor' => 500000, 'paid_on' => $paid->toDateString(), 'method' => 'bank'], $by);

            return;
        }
        $cat = $i % 6 === 5 ? $this->cats['student'] : $this->cats['ordinary'];
        $fee = (int) $cat->fee_minor;
        $pay = fn (Carbon $start, array $extra = []) => Memberships::renew($m->fresh(), $cat, $extra + ['starts_on' => $start->toDateString(), 'paid_minor' => $fee,
            'paid_on' => $start->toDateString(), 'method' => $method, 'reference' => $method === 'cash' ? null : 'REF'.str_pad((string) ($i * 37 + 1000), 6, '0', STR_PAD_LEFT)], $by);

        match ($kind) {
            // Paid last year, renewed this year: in force for months yet.
            'G' => (function () use ($pay, $today, $i) {
                $start = $today->copy()->subDays(60 + ($i * 13) % 250);
                $pay($start->copy()->subYear());
                $pay($start);
            })(),
            // The last period ended two to eight months ago.
            'L' => $pay($today->copy()->subYear()->subDays(60 + ($i * 17) % 180)),
            // Ends within the coming month.
            'E' => $pay($today->copy()->subYear()->addDays(3 + ($i * 7) % 25)),
            // Paid part of this year's fee: pending until the rest comes.
            'P' => Memberships::renew($m->fresh(), $cat, ['starts_on' => $today->copy()->subDays(20)->toDateString(), 'paid_minor' => intdiv($fee, 2), 'paid_on' => $today->copy()->subDays(20)->toDateString(), 'method' => 'mobile_money'], $by),
            // Fee waived by the committee this year.
            'W' => Memberships::renew($m->fresh(), $cat, ['starts_on' => $today->copy()->subDays(90)->toDateString(), 'status' => 'waived', 'amount_minor' => 0, 'notes' => 'Waived by the committee.'], $by),
            // New: no period yet.
            default => DB::table('sacco_members')->where('id', $m->id)->update(['category_id' => $cat->id, 'member_since' => $today->copy()->subDays(5 + $i % 20)->toDateString()]),
        };
    }
}

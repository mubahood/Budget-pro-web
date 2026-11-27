<?php

namespace App\Services\Kin\Demo;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\KinAnnouncement;
use App\Models\KinBranch;
use App\Models\KinLink;
use App\Models\KinSpouse;
use App\Models\Plan;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Kin\KinPurse;
use App\Services\Kin\TreeService;
use App\Services\Onboarding\OnboardingEvents;
use App\Services\Onboarding\OnboardingService;
use App\Services\Sacco\Dues\DuesService;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Shares\ShareService;
use App\Services\Sacco\Simple\LoanFlow;
use App\Services\Team\TeamService;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use Database\Seeders\FamilyRootsPlanSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Builds the demo family: "The Sample Family (Demo)", about sixty invented people over five generations. A founder with two
 * wives, five children, three branches (houses) of his sons, people who married in, a marriage that ended (a name only),
 * an adopted child, a guardian, the departed with their dates, one who left and one still being added. Every name is
 * made up, and the same every build (no randomness: the counters below pick from the lists).
 *
 * Build 2 adds what the family's home screen shows: ages in every band (one elder known by year only, one person with no
 * birth date), education, work and where people live; each sign-in is a person in the tree (user_id, for "my dues" and
 * "jump to me"); two contributions (a monthly subscription over six months, paid, part paid and owing, with one
 * reversed mistake; a one-off renovation half collected); three announcements, one pinned; and the family purse,
 * switched on through the real SACCO services: group savings deposits, shares and one running loan.
 */
final class KinDemoBuilder
{
    /** Bump when what is built changes: the next run rebuilds. */
    public const BUILD = 2;

    /** The sign-ins: key => [name, team role]. The head is the account's owner. */
    public const LOGINS = [
        'head' => ['name' => 'Petero Tibamwenda', 'role' => 'owner', 'label' => 'Head of the family', 'what' => 'Everything: people, branches, the team and settings'],
        'secretary' => ['name' => 'Ruth Tibamwenda', 'role' => 'kin_secretary', 'label' => 'Secretary', 'what' => 'Adds and edits people and branches'],
        'treasurer' => ['name' => 'Isaka Mugisha', 'role' => 'kin_treasurer', 'label' => 'Treasurer', 'what' => 'Sees the whole family'],
        'coordinator' => ['name' => 'Grace Tibamwenda', 'role' => 'kin_branch_coordinator', 'label' => 'Branch coordinator', 'what' => 'Looks after one branch\'s people'],
        'member' => ['name' => 'Brian Tibamwenda', 'role' => 'kin_member', 'label' => 'Family member', 'what' => 'Looks up anyone in the family'],
    ];

    private const MALE = ['Joel', 'Paulo', 'Isaka', 'Timoteo', 'Gideon', 'Silas', 'Benoni', 'Aloni', 'Caleb', 'Nathan', 'Edwin', 'Allan', 'Derrick', 'Ivan', 'Ryan', 'Elias', 'Ruben', 'Noah', 'Ethan', 'Liam'];

    private const FEMALE = ['Ruth', 'Lidia', 'Naomi', 'Dorika', 'Miriam', 'Agnesi', 'Grace', 'Joy', 'Faith', 'Mercy', 'Hope', 'Doreen', 'Brenda', 'Irene', 'Martha', 'Gloria', 'Peace', 'Ann', 'Rhoda', 'Precious'];

    private const SURNAMES = ['Akello', 'Nankunda', 'Kamara', 'Odongo', 'Atim', 'Mugisha', 'Asiimwe', 'Okello', 'Kyomugisha', 'Ainembabazi', 'Byamukama', 'Namara'];

    private const FAMILY = 'Tibamwenda';

    /** Children per couple, read in turn for each generation. */
    private const CHILDREN = [2 => [3, 2, 2, 1, 1], 3 => [2, 1, 1, 2, 1, 1, 2, 1, 1, 1], 4 => [1, 2, 1, 1]];

    public ?int $companyId = null;

    private Company $co;

    /** @var array<string, User> */
    private array $users = [];

    private string $token;

    private int $n = 0;

    /** @var array<int, array{gen: int, gender: string, born: int, branch: ?int, last: string}> */
    private array $people = [];

    /** @return array{company: Company, users: array<string, User>, snapshot: array<string, mixed>} */
    public function build(): array
    {
        $this->token = strtolower(Str::random(6));
        KinDemo::$building = true;
        try {
            OnboardingEvents::muted(function () {
                $this->createCompany();
                $this->createTeam();
                TreeService::batch(fn () => $this->grow());
                $this->linkLogins();
                $this->announce();
                $this->dues();
                $this->purse();
            });
        } finally {
            KinDemo::$building = false;
        }

        return ['company' => $this->co->fresh(), 'users' => array_map(fn (User $u) => $u->fresh(), $this->users),
            'snapshot' => ['build' => self::BUILD, 'users' => array_map(fn (User $u) => (int) $u->id, $this->users), 'people' => count($this->people)]];
    }

    private function createCompany(): void
    {
        $owner = $this->newLogin('head');
        $co = new Company();
        $co->owner_id = $owner->id;
        $co->name = (string) config('kin.demo.company_name', 'The Sample Family (Demo)');
        $co->status = 'Active';
        $co->is_demo = true;
        $co->demo_parent_id = null;
        $co->currency = 'UGX';
        $co->country = 'UG';
        $co->timezone = 'Africa/Kampala';
        $co->business_type = 'kin';
        $co->email = 'family@'.KinDemo::domain();
        $co->license_expire = now()->addYears(5);
        $co->enabled_modules = ['kin'];
        $co->receipt_channels = [];
        $co->onboarding_state = ['step' => 'done', 'completed_steps' => config('onboarding.steps'), 'skipped_steps' => [], 'completed_at' => now()->toIso8601String(),
            'dismissed_checklist' => true, 'demo' => true, 'version' => OnboardingService::STATE_VERSION, KinDemo::MARK => ['building' => true]];
        $co->save();
        $this->co = $co->fresh();
        $this->companyId = (int) $co->id;

        CompanyMember::create(['company_id' => $co->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $owner->refresh();
        User::ensureCompanyOwnerRole($owner);
        $this->users['head'] = $owner->fresh();

        $plan = (string) config('kin.demo.plan', 'family-roots-clan');
        if (! Plan::where('slug', $plan)->exists()) {
            (new FamilyRootsPlanSeeder())->run();
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
            $this->users[$key] = app(TeamService::class)->createMember($this->co, $this->users['head'], $l['name'], $this->tempEmail($key), null, Str::password(24, symbols: false), $l['role']);
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
        return "{$key}+build-{$this->token}@".KinDemo::domain();
    }

    // ── The family ─────────────────────────────────────────────────────

    private function grow(): void
    {
        $year = (int) now()->year;
        // Generation 1: the founder and his two wives, all departed.
        $founder = $this->person('Yakobo', self::FAMILY, 'male', 1921, ['gen' => 1, 'birth_date_precision' => 'year', 'status' => 'deceased', 'date_of_death' => '1990-03-14', 'place_of_death' => 'Home village',
            'heritage' => ['clan' => 'Sample clan', 'totem' => 'Sample totem', 'origin' => 'The hills']]);
        $w1 = $this->person('Esteri', 'Nabirye', 'female', 1926, ['gen' => 1, 'birth_date_precision' => 'year', 'status' => 'deceased', 'date_of_death' => '1999-08-02']);
        $w2 = $this->person('Rakeeri', 'Akello', 'female', 1931, ['gen' => 1, 'birth_date_precision' => 'about', 'status' => 'deceased', 'date_of_death' => '2008-01-20']);
        $this->marry($founder, $w1, 'widowed', 1, '1944-06-10');
        $this->marry($founder, $w2, 'widowed', 2, '1954-02-01');

        // Generation 2: five children; each son founds a branch (house).
        $kids = [[$w1, 'Erisa', 'male', 1946], [$w1, 'Mariamu', 'female', 1944], [$w1, 'Danieri', 'male', 1952], [$w2, 'Samwiri', 'male', 1956], [$w2, 'Yudesi', 'female', 1960]];
        $gen2 = [];
        foreach ($kids as $i => [$mother, $first, $gender, $born]) {
            $extra = match ($first) {
                'Erisa' => ['status' => 'deceased', 'date_of_death' => '2015-11-03', 'place_of_death' => 'Town hospital'],
                'Mariamu' => ['birth_date_precision' => 'year'], // the family's eldest living person: only the year is known
                default => [],
            };
            $id = $this->person($first, self::FAMILY, $gender, $born, $extra + ['gen' => 2]);
            $this->link($id, $founder, 'father');
            $this->link($id, $mother, 'mother');
            if ($gender === 'male') {
                $branch = KinBranch::create(['company_id' => $this->companyId, 'name' => 'House of '.$first, 'founder_id' => $id, 'sort_order' => $i,
                    'coordinator_user_id' => $first === 'Danieri' ? $this->users['coordinator']->id : null]);
                $this->setBranch($id, (int) $branch->id);
            }
            $gen2[] = $id;
        }
        // Daughters stay in the founder's first son's house.
        $first = $this->people[$gen2[0]]['branch'];
        foreach ($gen2 as $id) {
            if ($this->people[$id]['branch'] === null) {
                $this->setBranch($id, $first);
            }
        }
        // Samwiri's first marriage ended; only her name is kept.
        KinSpouse::create(['company_id' => $this->companyId, 'person_id' => $gen2[3], 'spouse_name' => 'Florence (first wife)', 'status' => 'ended', 'married_on' => '1978-05-06',
            'ended_on' => '1981-09-30', 'sort_order' => 1]);

        // Generations 3 to 5: each married couple's children, the older ones marrying in turn.
        $parents = $gen2;
        foreach ([2, 3, 4] as $gen) {
            $next = [];
            $pattern = self::CHILDREN[$gen];
            $c = 0;
            foreach ($parents as $pid) {
                $p = $this->people[$pid];
                if ($p['born'] > $year - 22) {
                    continue; // too young to marry
                }
                $spouse = $this->marriedIn($pid, $gen === 2 && $pid === $gen2[3] ? 2 : 1);
                $count = $pattern[$c++ % count($pattern)];
                for ($k = 0; $k < $count; $k++) {
                    $born = $p['born'] + 24 + 3 * $k;
                    if ($born > $year - 1) {
                        break;
                    }
                    $gender = ($this->n + $k) % 2 === 0 ? 'female' : 'male';
                    $last = $p['gender'] === 'male' ? $p['last'] : $this->people[$spouse]['last'];
                    $child = $this->person($this->pick($gender), $last, $gender, $born, ['gen' => $gen + 1]);
                    [$father, $mother] = $p['gender'] === 'male' ? [$pid, $spouse] : [$spouse, $pid];
                    $this->link($child, $father, 'father');
                    $this->link($child, $mother, 'mother');
                    $this->setBranch($child, $p['branch']);
                    $next[] = $child;
                }
            }
            $parents = $next;
        }

        // An adopted child and a guardian, in Danieri's house.
        $house = $this->people[$gen2[2]]['branch'];
        $couple = array_values(array_filter(array_keys($this->people), fn ($id) => $this->people[$id]['gen'] === 3 && $this->people[$id]['branch'] === $house && $this->people[$id]['gender'] === 'male'))[0] ?? null;
        if ($couple !== null) {
            $wife = KinSpouse::withoutGlobalScopes()->where('company_id', $this->companyId)->where('person_id', $couple)->value('spouse_id');
            $adopted = $this->person('Gloria', $this->people[$couple]['last'], 'female', 2009, ['gen' => 4]);
            $this->link($adopted, $couple, 'father', 'adoptive');
            if ($wife) {
                $this->link($adopted, (int) $wife, 'mother', 'adoptive');
            }
            $this->setBranch($adopted, $house);
            $ward = $this->person('Ivan', 'Okello', 'male', 2012, ['gen' => 4]);
            $this->link($ward, $gen2[2], 'parent', 'guardian');
            $this->setBranch($ward, $house);
        }

        // One who left the family's books, and one still being added (no date of birth yet).
        $leaver = $this->person('Allan', 'Kamara', 'male', 1983, ['status' => 'exited']);
        $this->marry($gen2[4], $leaver, 'ended', 1, '2005-04-02', '2011-07-15');
        $this->setBranch($leaver, $this->people[$gen2[4]]['branch']);
        $this->person('Precious', self::FAMILY, 'female', 0, ['status' => 'applicant']);
    }

    // ── Build 2: the sign-ins in the tree, news, contributions and the purse ──

    /** @var array<string, int> sign-in key => the person it is */
    private array $me = [];

    /** Each sign-in is a person in the tree (a living adult of the right sex), named as the demo door names them. */
    private function linkLogins(): void
    {
        $year = (int) now()->year;
        $coordBranch = (int) KinBranch::withoutGlobalScopes()->where('company_id', $this->companyId)->where('coordinator_user_id', $this->users['coordinator']->id)->value('id');
        $wants = [
            'head' => fn ($p) => $p['gen'] === 3 && $p['gender'] === 'male' && $p['last'] === self::FAMILY && $p['born'] <= $year - 40,
            'secretary' => fn ($p) => $p['gen'] === 3 && $p['gender'] === 'female' && $p['last'] === self::FAMILY,
            'coordinator' => fn ($p) => in_array($p['gen'], [3, 4], true) && $p['gender'] === 'female' && $p['branch'] === $coordBranch && $p['born'] <= $year - 25,
            'treasurer' => fn ($p) => $p['gen'] === 3 && $p['gender'] === 'male' && $p['last'] !== self::FAMILY,
            'member' => fn ($p) => $p['gen'] === 4 && $p['gender'] === 'male' && $p['born'] <= $year - 20 && $p['born'] > 0,
        ];
        foreach ($wants as $key => $want) {
            $gender = in_array($key, ['secretary', 'coordinator'], true) ? 'female' : 'male';
            $free = fn ($id, $p) => ! in_array($id, $this->me, true) && $p['gen'] >= 3 && $p['born'] > 0 && $p['born'] <= $year - 18 && $p['gender'] === $gender;
            $id = collect($this->people)->filter(fn ($p, $id) => $free($id, $p) && $want($p))->keys()->first()
                ?? collect($this->people)->filter(fn ($p, $id) => $free($id, $p))->keys()->first();
            if ($id === null) {
                continue;
            }
            [$first, $last] = explode(' ', self::LOGINS[$key]['name'], 2) + [1 => ''];
            $m = SaccoMember::withoutGlobalScopes()->find($id);
            $m->first_name = $first;
            $m->last_name = $last;
            $m->user_id = $this->users[$key]->id;
            $m->save();
            $this->people[$id]['last'] = $last;
            $this->me[$key] = (int) $id;
        }
    }

    private function announce(): void
    {
        $head = (int) $this->users['head']->id;
        $news = [
            ['Family meeting on the last Saturday of the month', "We meet at the old home at 10 in the morning. On the agenda: the renovation, this year's subscription and the end-of-year gathering. Bring a dish to share.", true, 2],
            ['Renovation: half way there', 'Thank you to everyone who has given towards the ancestral home. The roof is done; the walls and the water tank come next.', false, 9],
            ['Welcome to the newest members of the family', 'Please add new babies and new in-laws to the tree, or send their details to the secretary.', false, 20],
        ];
        foreach ($news as [$title, $body, $pinned, $daysAgo]) {
            $at = now()->subDays($daysAgo);
            KinAnnouncement::withoutGlobalScopes()->create(['company_id' => $this->companyId, 'title' => $title, 'body' => $body, 'pinned' => $pinned,
                'author_user_id' => $head, 'published_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
        }
    }

    /**
     * "Monthly family subscription" (fixed, six months: some paid up, some part paid, some owing, one mistaken payment
     * reversed) and "Ancestral home renovation" (once, any amount, half its target collected).
     */
    private function dues(): void
    {
        $dues = app(DuesService::class);
        $treasurer = $this->users['treasurer'];
        $today = CarbonImmutable::parse(LocalDate::today($this->companyId)->toDateString());
        $first = $today->subMonths(5)->startOfMonth();
        $monthly = $dues->create($this->co, ['name' => 'Monthly family subscription', 'kind' => 'recurring', 'period' => 'monthly', 'amount_mode' => 'fixed',
            'amount_minor' => 20_000, 'starts_on' => $first->toDateString(), 'description' => 'Every adult gives each month towards burials, school fees help and the family gathering.'], $treasurer);
        $people = DuesService::duePeople($this->companyId)->orderBy('id')->pluck('id')->all();
        $methods = ['mobile_money', 'cash', 'mobile_money', 'bank'];
        foreach (array_values($people) as $i => $id) {
            // How many months each person has paid: all six, five, four and a part, two, none.
            $months = [6, 5, 4, 2, 0, 6, 3][$i % 7];
            for ($k = 0; $k < $months; $k++) {
                $dues->pay($this->co, $monthly, ['member_id' => $id, 'amount_minor' => 20_000, 'method' => $methods[($i + $k) % 4],
                    'paid_on' => min($first->addMonths($k)->addDays(4 + $i % 10)->toDateString(), $today->toDateString())], $treasurer);
            }
            if ($i % 7 === 2) {
                $dues->pay($this->co, $monthly, ['member_id' => $id, 'amount_minor' => 10_000, 'method' => 'cash', 'paid_on' => $today->toDateString(), 'note' => 'Half now, the rest at the meeting'], $treasurer);
            }
        }
        // A payment put against the wrong person, reversed: the history keeps both rows.
        if (isset($people[4])) {
            $wrong = $dues->pay($this->co, $monthly, ['member_id' => $people[4], 'amount_minor' => 20_000, 'method' => 'mobile_money', 'paid_on' => $first->toDateString()], $treasurer);
            $dues->reverse($this->co, (int) $wrong->id, 'Recorded against the wrong person', $treasurer);
        }

        $home = $dues->create($this->co, ['name' => 'Ancestral home renovation', 'kind' => 'once', 'amount_mode' => 'any', 'target_minor' => 10_000_000,
            'starts_on' => $today->subMonths(3)->toDateString(), 'description' => 'A new roof, plastered walls and a water tank for the old home.'], $treasurer);
        $gifts = [1_000_000, 800_000, 700_000, 600_000, 500_000, 400_000, 400_000, 300_000, 200_000, 100_000]; // 5,000,000: half the target
        foreach ($gifts as $k => $amount) {
            $id = $people[(3 * $k + 1) % max(1, count($people))] ?? null;
            if ($id !== null) {
                $dues->pay($this->co, $home, ['member_id' => $id, 'amount_minor' => $amount, 'method' => $k % 2 ? 'bank' : 'mobile_money',
                    'paid_on' => $today->subDays(80 - 7 * $k)->toDateString()], $treasurer);
            }
        }
    }

    /** The family purse: switched on by the head for the sign-ins and a few more adults; savings, shares and one running loan. */
    private function purse(): void
    {
        if (! (bool) config('kin.purse_enabled', true)) {
            return;
        }
        $year = (int) now()->year;
        $adults = collect($this->people)->filter(fn ($p, $id) => $p['gen'] >= 3 && $p['born'] > 0 && $p['born'] <= $year - 21 && ! in_array($id, $this->me, true))->keys()->take(5)->all();
        $savers = array_values(array_unique([...array_values($this->me), ...$adults]));
        $head = $this->users['head'];
        $this->co = app(KinPurse::class)->enable($this->co, $head, $savers);

        $tx = app(SaccoTransactions::class);
        foreach ($savers as $i => $id) {
            $account = (int) SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $this->companyId)->where('member_id', $id)->where('status', '!=', 'closed')->value('id');
            foreach ([200_000 + 50_000 * ($i % 4), 100_000] as $amount) {
                $tx->record($this->co, ['kind' => 'deposit', 'savings_account_id' => $account, 'amount_minor' => $amount, 'channel' => 'cash', 'narration' => 'Family purse savings'], $head);
            }
            if ($i % 2 === 0) {
                app(ShareService::class)->purchase($this->co, ['member_id' => $id, 'shares_count' => 2 + $i % 3, 'channel' => 'cash'], $head);
            }
        }

        // One running loan: the member borrows, the treasurer guarantees, the head approves as chairperson and pays it out.
        if (isset($this->me['member'], $this->me['treasurer'])) {
            $flow = new LoanFlow();
            $borrower = SaccoMember::withoutGlobalScopes()->find($this->me['member']);
            $guarantor = SaccoMember::withoutGlobalScopes()->find($this->me['treasurer']);
            $loan = $flow->request($this->co, $borrower, ['amount_minor' => 500_000, 'months' => 6, 'purpose' => 'School fees for the new term', 'guarantor_member_id' => (int) $guarantor->id]);
            $flow->guarantorDecide($this->co, $guarantor, (int) $loan->id, 'approve', 'I know he will pay.');
            $flow->chairDecide($this->co, $head, (int) $loan->id, 'approve', 'Approved at the family meeting.');
            $flow->payOut($this->co, $head, (int) $loan->id, ['method' => 'cash', 'notes' => 'Paid in cash at the meeting']);
        }
    }

    /** @param array<string, mixed> $extra */
    private function person(string $first, string $last, string $gender, int $born, array $extra = []): int
    {
        $this->n++;
        $gen = (int) ($extra['gen'] ?? 0);
        unset($extra['gen']);
        $m = new SaccoMember($extra + ['company_id' => $this->companyId, 'kind' => 'individual', 'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
            'date_of_birth' => $born > 0 ? sprintf('%04d-%02d-%02d', $born, 1 + $this->n % 12, 1 + (7 * $this->n + intdiv($this->n, 12)) % 28) : null,
            // No phone numbers: a made-up one could be a real person's.
            'birth_date_precision' => $born > 0 ? 'exact' : null, 'status' => 'active', 'dues_duty' => $born > 0 && $born <= (int) now()->year - 18 ? 'compulsory' : 'exempt']
            + $this->profile($born));
        $m->save();
        $this->people[(int) $m->id] = ['gen' => $gen, 'gender' => $gender, 'born' => $born, 'branch' => null, 'last' => $last];

        return (int) $m->id;
    }

    /** Someone who married into the family: the same generation and branch, born a little before or after. */
    private function marriedIn(int $pid, int $order): int
    {
        $p = $this->people[$pid];
        $gender = $p['gender'] === 'male' ? 'female' : 'male';
        $born = $p['born'] + ($gender === 'female' ? 3 : -3);
        $id = $this->person($this->pick($gender), self::SURNAMES[$this->n % count(self::SURNAMES)], $gender, $born, ['gen' => $p['gen']]);
        $this->setBranch($id, $p['branch']);
        $this->marry($pid, $id, 'current', $order, sprintf('%04d-%02d-15', max($born, $p['born']) + 22, 1 + $this->n % 12));

        return $id;
    }

    private function marry(int $a, int $b, string $status, int $order, ?string $on, ?string $ended = null): void
    {
        KinSpouse::create(['company_id' => $this->companyId, 'person_id' => $a, 'spouse_id' => $b, 'status' => $status, 'married_on' => $on, 'ended_on' => $ended, 'sort_order' => $order]);
    }

    private function link(int $child, int $parent, string $role, string $kind = 'birth'): void
    {
        KinLink::create(['company_id' => $this->companyId, 'child_id' => $child, 'parent_id' => $parent, 'role' => $role, 'kind' => $kind]);
    }

    private function setBranch(int $id, ?int $branch): void
    {
        $this->people[$id]['branch'] = $branch;
        if ($branch !== null) {
            SaccoMember::withoutGlobalScopes()->whereKey($id)->update(['branch_id' => $branch]);
        }
    }

    private const DISTRICTS = ['Kampala', 'Wakiso', 'Mbarara', 'Kabale', 'Mukono', 'Jinja', 'Fort Portal', 'Masaka'];

    private const WORK = ['Teacher', 'Farmer', 'Nurse', 'Trader', 'Engineer', 'Accountant', 'Driver', 'Tailor', 'Doctor', 'Mechanic', 'Carpenter', 'Shop keeper'];

    private const SCHOOLING = ['secondary', 'certificate', 'diploma', 'degree', 'primary', 'masters', 'diploma', 'degree', 'secondary', 'phd'];

    /** Schooling, work and home by age, picked in turn (a few live abroad). @return array<string, ?string> */
    private function profile(int $born): array
    {
        if ($born <= 0) {
            return ['country' => 'Uganda'];
        }
        $age = (int) now()->year - $born;
        $abroad = $age >= 22 && $age <= 60 && $this->n % 9 === 0;
        $education = match (true) {
            $age < 6 => null,
            $age <= 12 => 'primary',
            $age <= 18 => 'secondary',
            default => self::SCHOOLING[$this->n % count(self::SCHOOLING)],
        };
        $work = match (true) {
            $age < 6 => null,
            $age <= 22 => 'Student',
            $age >= 66 => $this->n % 2 === 0 ? 'Retired' : 'Farmer',
            default => self::WORK[$this->n % count(self::WORK)],
        };

        return ['country' => $abroad ? ($this->n % 2 === 0 ? 'Kenya' : 'United Kingdom') : 'Uganda', 'district' => $abroad ? null : self::DISTRICTS[$this->n % count(self::DISTRICTS)],
            'education_level' => $education, 'occupation' => $work];
    }

    private function pick(string $gender): string
    {
        $list = $gender === 'male' ? self::MALE : self::FEMALE;

        return $list[(3 * $this->n) % count($list)];
    }
}

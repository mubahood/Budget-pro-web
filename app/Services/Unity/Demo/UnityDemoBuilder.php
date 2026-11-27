<?php

namespace App\Services\Unity\Demo;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Plan;
use App\Models\SaccoMember;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\UnityCategory;
use App\Models\UnityChapter;
use App\Models\User;
use App\Services\Onboarding\OnboardingEvents;
use App\Services\Onboarding\OnboardingService;
use App\Services\Team\TeamService;
use App\Services\Unity\MemberActions;
use App\Services\Unity\Memberships;
use App\Support\LocalDate;
use App\Support\Sync\SyncSequence;
use Database\Seeders\UnityDeskPlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds the demo association, "Sample Association (Demo)": 300 invented members in seven chapters under three regions,
 * on three categories (Ordinary annual, Student annual, Life). Members joined over fifteen years; their ages, gender,
 * schooling, work and home areas are mixed, and some details are left blank as in real books. There are applicants,
 * suspended, departed and deceased members, three years of dues (most paid up, some lapsed, some part paid), and a year
 * of group life (UnityDemoActivity): committees, meetings, drives, welfare, ballots and announcements.
 *
 * Every name is made up and nobody has a phone number. The build is the same every time: crc32 of a label picks from
 * the lists, never a random number. Dates are counted back from today, so the home screen always looks current.
 */
final class UnityDemoBuilder
{
    /** Bump when what is built changes: the next run rebuilds. */
    public const BUILD = 2;

    public const MEMBERS = 300;

    /** The sign-ins: key => [name, team role]. The chair is the account's owner. Each is also a member of the association. */
    public const LOGINS = [
        'chair' => ['name' => 'Agnes Nakato', 'role' => 'owner', 'label' => 'Chair'],
        'secretary' => ['name' => 'Moses Okiror', 'role' => 'unity_secretary', 'label' => 'Secretary'],
        'treasurer' => ['name' => 'Harriet Achieng', 'role' => 'unity_treasurer', 'label' => 'Treasurer'],
        'lead' => ['name' => 'Samuel Kiggundu', 'role' => 'unity_chapter_lead', 'label' => 'Chapter lead'],
        'member' => ['name' => 'Juliet Auma', 'role' => 'unity_member', 'label' => 'Member'],
    ];

    /** The officers' own details: [female, years a member, age, chapter index, education, occupation]. */
    private const OFFICERS = [
        'chair' => [true, 14, 52, 0, 'postgraduate', 'Teacher'],
        'secretary' => [false, 9, 41, 1, 'degree', 'Accountant'],
        'treasurer' => [true, 11, 47, 0, 'degree', 'Accountant'],
        'lead' => [false, 7, 38, 0, 'diploma', 'Civil engineer'],
        'member' => [true, 4, 29, 0, 'diploma', 'Nurse'],
    ];

    /** Regions, then chapters: [name, code, region index, district, subcounties, place]. */
    public const REGIONS = ['Central Region', 'Eastern Region', 'Western Region'];

    public const CHAPTERS = [
        ['Kampala', 'KLA', 0, 'Kampala', ['Central Division', 'Kawempe', 'Makindye', 'Nakawa', 'Rubaga'], 'Kampala'],
        ['Wakiso', 'WKS', 0, 'Wakiso', ['Nansana', 'Kira', 'Kasangati', 'Entebbe'], 'Nansana'],
        ['Mukono', 'MKN', 0, 'Mukono', ['Goma', 'Nama', 'Seeta'], 'Mukono'],
        ['Jinja', 'JJA', 1, 'Jinja', ['Bugembe', 'Mafubira', 'Budondo'], 'Jinja'],
        ['Mbale', 'MBL', 1, 'Mbale', ['Industrial Division', 'Northern Division', 'Bungokho'], 'Mbale'],
        ['Mbarara', 'MBR', 2, 'Mbarara', ['Kakoba', 'Nyamitanga', 'Kamukuzi'], 'Mbarara'],
        ['Fort Portal', 'FTP', 2, 'Kabarole', ['Fort Portal Central', 'Karambi', 'Kibiito'], 'Fort Portal'],
    ];

    /** Which chapter a member joins, read by crc32: Kampala and Wakiso are the biggest, Fort Portal the smallest. */
    private const SLOTS = [0, 0, 0, 0, 1, 1, 1, 2, 2, 2, 3, 3, 3, 4, 4, 5, 5, 5, 6, 6];

    private const FEMALE = ['Ruth', 'Lydia', 'Naomi', 'Dorcas', 'Miriam', 'Agatha', 'Grace', 'Joy', 'Faith', 'Mercy', 'Hope', 'Doreen', 'Brenda',
        'Irene', 'Martha', 'Gloria', 'Peace', 'Ann', 'Rhoda', 'Esther', 'Sarah', 'Prossy', 'Winnie', 'Sylvia', 'Juliet', 'Florence', 'Harriet',
        'Christine', 'Patience', 'Rose', 'Annet', 'Betty', 'Cissy', 'Edith', 'Jane', 'Lillian', 'Olivia', 'Stella', 'Vivian', 'Zawedde'];

    private const MALE = ['Joel', 'Paul', 'Isaac', 'Timothy', 'Gideon', 'Silas', 'Daniel', 'Caleb', 'Nathan', 'Edwin', 'Allan', 'Derrick', 'Ivan',
        'Ronald', 'Elias', 'Peter', 'Simon', 'Martin', 'Brian', 'Henry', 'Moses', 'Patrick', 'Godfrey', 'Emmanuel', 'Charles', 'Francis', 'Joseph',
        'Robert', 'Stephen', 'Kenneth', 'Andrew', 'Fred', 'George', 'Hassan', 'Ibrahim', 'Julius', 'Lawrence', 'Michael', 'Noah', 'Richard'];

    private const SURNAMES = ['Akello', 'Nankunda', 'Kamara', 'Odongo', 'Atim', 'Mugisha', 'Asiimwe', 'Okello', 'Kyomugisha', 'Ainembabazi',
        'Byamukama', 'Namara', 'Wasswa', 'Nabirye', 'Opio', 'Tumusiime', 'Ssempala', 'Nalubega', 'Mutebi', 'Namutebi', 'Ochieng', 'Adong',
        'Kato', 'Babirye', 'Mwesigwa', 'Twinomugisha', 'Nakimuli', 'Lubega', 'Ouma', 'Apio', 'Wanyama', 'Nambozo', 'Kizza', 'Nassali',
        'Turyahikayo', 'Kobusingye', 'Ejiri', 'Amoding', 'Bwambale', 'Kabugo', 'Nakalema', 'Ogwang', 'Achan', 'Muwonge', 'Nanyonjo'];

    /** Work, with the common ones listed more than once so they come up more often. */
    private const OCCUPATIONS = ['Teacher', 'Teacher', 'Teacher', 'Trader', 'Trader', 'Farmer', 'Farmer', 'Nurse', 'Nurse', 'Accountant', 'Student',
        'Student', 'Boda boda rider', 'Tailor', 'Civil engineer', 'Driver', 'Shopkeeper', 'Clinical officer', 'Mechanic', 'Hairdresser',
        'Police officer', 'Retired', 'Banker', 'Carpenter'];

    private const EDUCATION = ['none', 'primary', 'primary', 'o_level', 'o_level', 'o_level', 'a_level', 'a_level', 'certificate', 'certificate',
        'diploma', 'diploma', 'diploma', 'degree', 'degree', 'degree', 'postgraduate'];

    private const RELATIONS = ['Spouse', 'Spouse', 'Spouse', 'Brother', 'Sister', 'Mother', 'Father', 'Son', 'Daughter', 'Cousin', 'Friend'];

    private const AVATAR_TONES = ['#e8ebf3', '#e6eee9', '#f2ece4', '#ece8f2'];

    /** Members who have a birthday in the next seven days (index => days from today), and members whose joining day comes round. */
    private const BIRTHDAYS = [4 => 3, 12 => 0, 57 => 1, 93 => 2, 140 => 4, 177 => 5, 222 => 6, 260 => 2];

    private const ANNIVERSARIES = [30 => 1, 45 => 3, 88 => 0, 150 => 5, 201 => 6];

    public ?int $companyId = null;

    private Company $co;

    /** @var array<string, User> */
    private array $users = [];

    private string $token;

    private Carbon $today;

    /** @var array<string, UnityCategory> */
    private array $cats = [];

    /** @var list<UnityChapter> */
    private array $regions = [];

    /** @var list<UnityChapter> */
    private array $chapters = [];

    /**
     * Every member: index => [id, chapter index, fate, since (Y-m-d|null), age (|null), occupation, model].
     *
     * @var array<int, array<string, mixed>>
     */
    private array $people = [];

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
                (new UnityDemoActivity($this->co, $this->users, $this->people, $this->chapters, $this->today))->run();
            });
        } finally {
            UnityDemo::$building = false;
        }

        return ['company' => $this->co->fresh(), 'users' => array_map(fn (User $u) => $u->fresh(), $this->users),
            'snapshot' => ['build' => self::BUILD, 'users' => array_map(fn (User $u) => (int) $u->id, $this->users),
                'members' => SaccoMember::withoutGlobalScopes()->where('company_id', $this->companyId)->count()]];
    }

    /** A number from 0 to $n - 1 picked by a label: the same label always gives the same number. */
    public static function pick(string $label, int $n): int
    {
        return $n <= 1 ? 0 : crc32($label) % $n;
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
        $this->today = Carbon::parse(LocalDate::today($this->companyId)->toDateString());
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

    private function grow(): void
    {
        $cid = $this->companyId;
        $this->cats = [
            'ordinary' => UnityCategory::create(['company_id' => $cid, 'name' => 'Ordinary annual', 'fee_minor' => 50000, 'period_months' => 12]),
            'student' => UnityCategory::create(['company_id' => $cid, 'name' => 'Student annual', 'fee_minor' => 20000, 'period_months' => 12]),
            'life' => UnityCategory::create(['company_id' => $cid, 'name' => 'Life', 'fee_minor' => 500000, 'period_months' => 0]),
        ];
        foreach (self::REGIONS as $r => $name) {
            $this->regions[$r] = UnityChapter::create(['company_id' => $cid, 'kind' => 'region', 'name' => $name, 'code' => strtoupper(substr($name, 0, 3)).'R', 'sort_order' => $r]);
        }
        foreach (self::CHAPTERS as $c => [$name, $code, $region, , , $place]) {
            $this->chapters[$c] = UnityChapter::create(['company_id' => $cid, 'kind' => 'chapter', 'name' => $name, 'code' => $code,
                'parent_id' => $this->regions[$region]->id, 'location' => $place, 'sort_order' => $c]);
        }

        $logins = array_keys(self::LOGINS);
        $used = [];
        for ($i = 0; $i < self::MEMBERS; $i++) {
            $this->people[$i] = $this->member($i, $logins[$i] ?? null, $used);
        }
        $this->joinedEvents();
        $this->photos();
        foreach ($this->people as $i => $p) {
            $this->books($i, $p);
        }
        $this->fates();
        Memberships::recomputeAll($cid);
    }

    /** What happens to each member: most are current; a few apply, are turned down, are suspended, leave or die. */
    private static function fate(int $i): string
    {
        return match (true) {
            $i < 5 => 'current',
            $i >= 298 => 'rejected',
            $i >= 290 => 'applicant',
            $i >= 288 => 'approved', // applied, approved this month
            $i % 61 === 7 => 'deceased',
            $i % 29 === 11 => 'exited',
            $i % 53 === 20 => 'suspended',
            default => 'current',
        };
    }

    /** @param  array<string, true>  $used */
    private function member(int $i, ?string $login, array &$used): array
    {
        $today = $this->today;
        $fate = self::fate($i);
        if ($login !== null) {
            [$female, $years, $age, $chap, $edu, $job] = self::OFFICERS[$login];
            [$first, $last] = explode(' ', self::LOGINS[$login]['name'], 2);
            $gender = $female ? 'female' : 'male';
            $since = $today->copy()->subYears($years)->subDays(40 + $i * 23);
        } else {
            $g = self::pick("g{$i}", 100);
            $gender = $g >= 97 ? null : ($g < 52 ? 'female' : 'male');
            $names = $gender === 'male' || ($gender === null && $i % 2) ? self::MALE : self::FEMALE;
            for ($k = 0; ; $k++) { // no two members share a full name
                $first = $names[self::pick("f{$i}-{$k}", count($names))];
                $last = self::SURNAMES[self::pick("l{$i}-{$k}", count(self::SURNAMES))];
                if (! isset($used["{$first} {$last}"]) && ! in_array("{$first} {$last}", array_column(self::LOGINS, 'name'), true)) {
                    break;
                }
            }
            $a = self::pick("ab{$i}", 100);
            $age = self::pick("a{$i}", 100) < 11 ? null : match (true) {
                $a < 4 => 16 + self::pick("ay{$i}", 2),
                $a < 16 => 18 + self::pick("ay{$i}", 8),
                $a < 40 => 26 + self::pick("ay{$i}", 10),
                $a < 66 => 36 + self::pick("ay{$i}", 10),
                $a < 88 => 46 + self::pick("ay{$i}", 15),
                default => 61 + self::pick("ay{$i}", 18),
            };
            $chap = self::SLOTS[self::pick("c{$i}", count(self::SLOTS))];
            $edu = self::pick("e{$i}", 100) < 10 ? null : self::EDUCATION[self::pick("ed{$i}", count(self::EDUCATION))];
            $job = $age !== null && $age < 22 && self::pick("st{$i}", 3) > 0 ? 'Student'
                : (self::pick("o{$i}", 100) < 8 ? null : self::OCCUPATIONS[self::pick("oc{$i}", count(self::OCCUPATIONS))]);
            if ($job === 'Student' && $age !== null && $age > 30) {
                $job = 'Teacher';
            }
            // The newest 33 joined over the last year (the growth chart); the rest from 13 months to 15 years ago.
            $since = $i >= 255 && $i < 290 ? $today->copy()->subDays(min(353, (289 - $i) * 11 + 1)) : $today->copy()->subDays(400 + self::pick("ms{$i}", 5100));
        }
        $used["{$first} {$last}"] = true;

        $dob = $age === null ? null : $today->copy()->subYears($age)->subDays(10 + self::pick("dd{$i}", 340));
        if (isset(self::BIRTHDAYS[$i]) && $age !== null) {
            $dob = $today->copy()->addDays(self::BIRTHDAYS[$i])->subYears($age);
        }
        if ($dob !== null && $since->lt($dob->copy()->addYears(16)) && $i < 255) { // nobody joined before sixteen
            $since = $dob->copy()->addYears(16)->addDays(self::pick("sx{$i}", 200));
            if ($since->gte($today->copy()->subDays(400))) {
                $since = $today->copy()->subDays(400 + self::pick("sy{$i}", 300));
            }
        }
        if (isset(self::ANNIVERSARIES[$i])) {
            $since = $today->copy()->addDays(self::ANNIVERSARIES[$i])->subYears(2 + self::pick("an{$i}", 11));
        }

        [, , , $district, $subs] = self::CHAPTERS[$chap];
        $d = self::pick("di{$i}", 100);
        if ($d < 6 && $login === null) {
            $district = null;
        } elseif ($d < 14) { // lives in a neighbouring district
            $other = self::CHAPTERS[($chap + 1) % count(self::CHAPTERS)];
            [$district, $subs] = [$other[3], $other[4]];
        }
        $subcounty = $district === null || self::pick("sc{$i}", 100) < 10 ? null : $subs[self::pick("su{$i}", count($subs))];
        $kin = $login !== null || self::pick("k{$i}", 100) < 58;
        $applicant = in_array($fate, ['applicant', 'rejected', 'approved'], true);

        $m = new SaccoMember(['company_id' => $this->companyId, 'kind' => 'individual', 'first_name' => $first, 'last_name' => $last,
            'gender' => $gender, 'date_of_birth' => $dob?->toDateString(), 'education_level' => $edu, 'occupation' => $job,
            'district' => $district, 'subcounty' => $subcounty, 'country' => 'UG',
            'email' => $login !== null ? UnityDemo::email($login) : null,
            'status' => $applicant ? 'applicant' : 'active',
            'joined_on' => $applicant ? $today->copy()->subDays(2 + ($i % 10) * 3)->toDateString() : $since->toDateString(),
            'member_since' => $applicant ? null : $since->toDateString(),
            'chapter_id' => $this->chapters[$chap]->id,
            'next_of_kin_name' => $kin ? self::FEMALE[self::pick("kf{$i}", 40)].' '.$last : null,
            'next_of_kin_relation' => $kin ? self::RELATIONS[self::pick("kr{$i}", count(self::RELATIONS))] : null,
            'created_by_id' => $this->users['secretary']->id]);
        if ($kin && in_array(self::RELATIONS[self::pick("kr{$i}", count(self::RELATIONS))], ['Brother', 'Father', 'Son'], true)) {
            $m->next_of_kin_name = self::MALE[self::pick("km{$i}", 40)].' '.$last;
        }
        $m->save();

        return ['id' => (int) $m->id, 'chap' => $chap, 'fate' => $fate, 'since' => $applicant ? null : $since->toDateString(),
            'age' => $age, 'job' => $job, 'login' => $login, 'name' => "{$first} {$last}"];
    }

    /** One "joined" line in every member's history, on the day they joined. */
    private function joinedEvents(): void
    {
        $now = now();
        $seq = SyncSequence::reserve(count($this->people));
        $rows = [];
        foreach ($this->people as $p) {
            $on = $p['since'] ?? DB::table('sacco_members')->where('id', $p['id'])->value('joined_on');
            $rows[] = ['company_id' => $this->companyId, 'uuid' => (string) Str::uuid(), 'member_id' => $p['id'], 'kind' => 'joined',
                'to_value' => self::CHAPTERS[$p['chap']][0], 'on' => $on, 'by_user_id' => $this->users['secretary']->id,
                'server_seq' => $seq++, 'version' => 1, 'is_deleted' => 0, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('unity_member_events')->insert($chunk);
        }
    }

    /**
     * Most members have a photo: a flat initials picture (the demo shows no faces of real people). About one in four,
     * and every applicant, has none, so "missing photo" has people in it.
     */
    private function photos(): void
    {
        $font = public_path('vendor/laravel-admin/google-fonts/fonts/Source-Sans-Pro-Semibold.ttf');
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext') || ! is_file($font)) {
            return;
        }
        $folder = "files/{$this->companyId}/".now()->format('Y/m');
        foreach ($this->people as $i => $p) {
            if ($p['since'] === null || ($p['login'] === null && self::pick("p{$i}", 100) < 26)) {
                continue;
            }
            [$first, $last] = explode(' ', $p['name'], 2);
            $png = self::avatar(mb_substr($first, 0, 1).mb_substr($last, 0, 1), self::AVATAR_TONES[$i % count(self::AVATAR_TONES)], $font);
            $uuid = (string) Str::uuid();
            $path = "{$folder}/{$uuid}.png";
            Storage::disk('public')->put($path, $png);
            $f = StoredFile::withoutGlobalScopes()->create(['company_id' => $this->companyId, 'uuid' => $uuid, 'purpose' => 'avatar', 'path' => $path,
                'mime' => 'image/png', 'size_bytes' => strlen($png), 'uploaded_by_id' => $this->users['secretary']->id]);
            DB::table('sacco_members')->where('id', $p['id'])->update(['photo_file_id' => $f->id]);
        }
    }

    private static function avatar(string $initials, string $tone, string $font): string
    {
        $size = 256;
        $img = imagecreatetruecolor($size, $size);
        $rgb = fn (string $hex) => array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        imagefilledrectangle($img, 0, 0, $size, $size, imagecolorallocate($img, ...$rgb($tone)));
        $ink = imagecolorallocate($img, ...$rgb('#2B3A67'));
        $box = imagettfbbox(96, 0, $font, $initials);
        $x = (int) (($size - ($box[2] - $box[0])) / 2 - $box[0]);
        $y = (int) (($size - ($box[1] - $box[7])) / 2 - $box[7]);
        imagettftext($img, 96, 0, $x, $y, $ink, $font, $initials);
        ob_start();
        imagepng($img, null, 9);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /**
     * Three years of dues. The member's pattern, read by crc32: G paid every year and in force; E ends within the month;
     * L stopped paying (some long enough to be set aside as dormant); P part paid this year; W waived this year; A paid
     * once long ago and dormant. Every 17th member bought a life membership. Recent joiners paid on joining, except the
     * newest, who owe their first fee. Departed members paid until a while before they left.
     */
    private function books(int $i, array $p): void
    {
        if ($p['since'] === null) {
            DB::table('sacco_members')->where('id', $p['id'])->update(['category_id' => $this->cats['ordinary']->id]);

            return;
        }
        $today = $this->today;
        $since = Carbon::parse($p['since']);
        $student = $p['job'] === 'Student';
        $cat = $student ? $this->cats['student'] : $this->cats['ordinary'];
        $r = self::pick("b{$i}", 100);
        $kind = match (true) {
            $p['login'] === 'member' => 'E',
            $p['login'] !== null => 'G',
            in_array($p['fate'], ['exited', 'deceased'], true) => 'L',
            $i % 17 === 3 && $p['fate'] !== 'approved' => 'life',
            $since->gt($today->copy()->subDays(40)) => 'N',
            $since->gt($today->copy()->subYear()) => 'J',
            $r < 60 => 'G',
            $r < 67 => 'E',
            $r < 81 => 'L',
            $r < 87 => 'P',
            $r < 91 => 'W',
            default => 'A',
        };
        $pay = function (Carbon $start, ?int $paid = null, array $extra = []) use ($i, $p, $cat, $today) {
            $method = ['mobile_money', 'mobile_money', 'cash', 'bank'][self::pick("pm{$i}-{$start->year}", 4)];
            $on = $start->copy()->addDays(self::pick("pd{$i}-{$start->year}", 15));
            $on = $on->gt($today) ? $today->copy() : $on;
            $member = SaccoMember::withoutGlobalScopes()->find($p['id']);
            Memberships::renew($member, $cat, $extra + ['starts_on' => $start->toDateString(), 'paid_minor' => $paid ?? (int) $cat->fee_minor,
                'paid_on' => $on->toDateString(), 'method' => $method,
                'reference' => $method === 'cash' ? null : ($method === 'bank' ? 'BNK' : 'MM').str_pad((string) (crc32("ref{$i}-{$start->year}") % 1000000), 6, '0', STR_PAD_LEFT)], $this->users['treasurer']->id);
        };
        // The periods before the last one, back to three years or the joining day.
        $earlier = function (Carbon $last, int $max = 2) use ($pay, $since) {
            for ($k = $max; $k >= 1; $k--) {
                $s = $last->copy()->subYears($k);
                if ($s->gte($since)) {
                    $pay($s);
                }
            }
        };
        $anniversary = $since->copy()->setYear($today->year);
        if ($anniversary->gt($today)) {
            $anniversary->subYear();
        }

        switch ($kind) {
            case 'life':
                $start = $since->copy()->addDays(30)->min($today);
                $member = SaccoMember::withoutGlobalScopes()->find($p['id']);
                Memberships::renew($member, $this->cats['life'], ['starts_on' => $start->toDateString(), 'paid_minor' => 500000,
                    'paid_on' => $start->toDateString(), 'method' => 'bank', 'reference' => 'BNK'.str_pad((string) ($i * 911 % 1000000), 6, '0', STR_PAD_LEFT)], $this->users['treasurer']->id);
                break;
            case 'G':
                $earlier($anniversary);
                $pay($anniversary);
                break;
            case 'E':
                $last = $today->copy()->subYear()->addDays(3 + self::pick("en{$i}", 26));
                $earlier($last);
                $pay($last->max($since));
                break;
            case 'L':
                $last = $today->copy()->subYear()->subDays(20 + self::pick("lp{$i}", 400))->max($since);
                $earlier($last, 1);
                $pay($last);
                break;
            case 'P':
                $last = $today->copy()->subDays(5 + self::pick("pp{$i}", 35));
                $earlier($last);
                $pay($last, intdiv((int) $cat->fee_minor, 2));
                break;
            case 'W':
                $last = $today->copy()->subDays(30 + self::pick("wv{$i}", 170));
                $earlier($last);
                $member = SaccoMember::withoutGlobalScopes()->find($p['id']);
                Memberships::renew($member, $cat, ['starts_on' => $last->toDateString(), 'status' => 'waived', 'amount_minor' => 0,
                    'notes' => 'Waived by the committee for this year.'], $this->users['treasurer']->id);
                break;
            case 'A':
                $pay($since->copy()->max($today->copy()->subYears(3)->subDays(self::pick("aa{$i}", 200))));
                break;
            case 'J':
                $pay($since->copy());
                break;
            default: // N: owes the first fee
                DB::table('sacco_members')->where('id', $p['id'])->update(['category_id' => $cat->id]);
        }
        $this->people[$i]['books'] = $kind;
    }

    /** Status changes, through the same actions an officer uses (then dated in the past where they happened earlier). */
    private function fates(): void
    {
        $chair = $this->users['chair'];
        $sec = $this->users['secretary'];
        $exitReasons = ['Moved abroad for work.', 'Resigned in writing.', 'Moved to another town.', 'No longer able to take part.', 'Joined the staff of a member body.'];
        $places = ['Mulago Hospital, Kampala', 'At home', 'Jinja Regional Referral Hospital', null, 'Mbarara Regional Referral Hospital'];
        $n = ['exited' => 0, 'deceased' => 0, 'suspended' => 0, 'dormant' => 0];
        foreach ($this->people as $i => $p) {
            $m = SaccoMember::withoutGlobalScopes()->find($p['id']);
            switch ($p['fate']) {
                case 'approved':
                    MemberActions::approve($m, $sec);
                    $this->people[$i]['since'] = $this->today->toDateString();
                    break;
                case 'rejected':
                    MemberActions::reject($m, $sec, 'Lives outside the area the association serves.');
                    break;
                case 'exited':
                    $k = $n['exited']++;
                    MemberActions::exit($m, $sec, $exitReasons[$k % 5], $this->today->copy()->subDays(9 + $k * 33)->toDateString());
                    break;
                case 'deceased':
                    $k = $n['deceased']++;
                    MemberActions::died($m, $chair, $this->today->copy()->subDays(21 + $k * 67)->toDateString(), $places[$k % 5]);
                    break;
                case 'suspended':
                    $k = $n['suspended']++;
                    MemberActions::suspend($m, $chair, 'Suspended by the Executive Committee pending a disciplinary hearing.');
                    $this->backdate($p['id'], 'suspended', $this->today->copy()->subDays(12 + $k * 19));
                    break;
                default:
                    // Long lapsed members are set aside as dormant (still members, counted as lapsed on the home screen).
                    if (in_array($p['books'] ?? null, ['A', 'L'], true) && $p['login'] === null) {
                        $ends = DB::table('unity_subscriptions')->where('member_id', $p['id'])->max('ends_on');
                        if ($ends !== null && Carbon::parse($ends)->lt($this->today->copy()->subDays(180)) && ($p['books'] === 'A' || self::pick("dm{$i}", 2) === 0)) {
                            $on = Carbon::parse($ends)->addDays(90)->min($this->today);
                            DB::table('sacco_members')->where('id', $p['id'])->update(['status' => 'dormant', 'status_changed_on' => $on->toDateString()]);
                            MemberActions::log($m, 'status', 'active', 'dormant', 'No dues for over a year.', $on, (int) $sec->id);
                            $this->people[$i]['fate'] = 'dormant';
                            $n['dormant']++;
                        }
                    }
            }
        }
        // A few members moved to another chapter during the year.
        foreach ([[33, 3], [118, 0], [207, 5]] as $k => [$i, $to]) {
            $p = $this->people[$i];
            if ($p['fate'] !== 'current' || $p['chap'] === $to) {
                continue;
            }
            MemberActions::transfer(SaccoMember::withoutGlobalScopes()->find($p['id']), $sec, (int) $this->chapters[$to]->id, 'Moved for work.');
            DB::table('unity_member_events')->where('member_id', $p['id'])->where('kind', 'transferred')
                ->update(['on' => $this->today->copy()->subDays(45 + $k * 50)->toDateString()]);
            $this->people[$i]['chap'] = $to;
        }
    }

    private function backdate(int $memberId, string $kind, Carbon $on): void
    {
        DB::table('sacco_members')->where('id', $memberId)->update(['status_changed_on' => $on->toDateString()]);
        DB::table('unity_member_events')->where('member_id', $memberId)->where('kind', $kind)->update(['on' => $on->toDateString()]);
    }
}

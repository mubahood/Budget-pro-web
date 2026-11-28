<?php

namespace App\Services\Kin;

use App\Models\Company;
use App\Models\SaccoDuesProgramme;
use App\Services\Sacco\Dues\DuesService;
use App\Services\Sacco\PostingCurrency;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * GET kin/stats: the family in numbers (doc 12 §12.4). Each figure has its own query, so one cannot spoil another.
 *
 * Who counts: the family is every person not removed or merged. The late are those departed (status deceased or a date of
 * death); "living" is everyone else except those gone from the family's books (exited). Gender, ages, education, places,
 * occupations and the facts count the living only. An age needs a birth date: an exact one, or a year only (then the age
 * is this year less that year). Birthdays need an exact date.
 */
class FamilyStats
{
    public const AGE_BANDS = ['0-12' => [0, 12], '13-17' => [13, 17], '18-25' => [18, 25], '26-35' => [26, 35], '36-50' => [36, 50], '51-65' => [51, 65], '66-80' => [66, 80], '81+' => [81, 999]];

    public const EDUCATION = ['none', 'primary', 'secondary', 'certificate', 'diploma', 'degree', 'masters', 'phd', 'unknown'];

    private int $cid;

    private CarbonImmutable $today;

    public function __construct(private Company $company, private ?int $meId = null)
    {
        $this->cid = (int) $company->id;
        $this->today = CarbonImmutable::parse(LocalDate::today($this->cid)->toDateString());
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $tz = (string) ($this->company->timezone ?: config('app.timezone'));

        return [
            'as_of' => now()->setTimezone($tz)->toIso8601String(),
            'currency' => PostingCurrency::forCompany($this->company),
            'population' => $this->population(),
            'generations' => $this->generations(),
            'branches' => $this->branches(),
            'age_bands' => $this->ageBands(),
            'education' => $this->education(),
            'places' => ['countries' => $this->countOf('country'), 'districts' => $this->countOf('district')],
            'occupations' => $this->countOf('occupation'),
            'birthdays' => $this->birthdays(),
            'memorials' => ['next_30_days' => $this->memorials()],
            'recent_people' => $this->recent(),
            'facts' => $this->facts(),
            'dues' => $this->dues(),
            'purse' => $this->purse(),
            'announcement' => $this->announcement(),
            'next_event' => $this->nextEvent(),
        ];
    }

    // ---------------------------------------------------------------- who

    private function family()
    {
        return DB::table('sacco_members')->where('company_id', $this->cid)->where('is_deleted', 0)->whereNull('merged_into_id');
    }

    private function living()
    {
        return $this->family()->where('status', '!=', 'deceased')->whereNull('date_of_death')->where('status', '!=', 'exited');
    }

    private function late()
    {
        return $this->family()->where(fn ($q) => $q->where('status', 'deceased')->orWhereNotNull('date_of_death'));
    }

    private static function name(object $r): string
    {
        return trim(implode(' ', array_filter([$r->first_name ?? null, $r->other_names ?? null, $r->last_name ?? null])));
    }

    /** Age today from a birth date: exact (birthday passed or not) or a year only. Null when unknown or in the future. */
    public static function age(?string $born, ?string $precision, CarbonImmutable $today): ?int
    {
        if ($born === null) {
            return null;
        }
        $b = CarbonImmutable::parse($born);
        if ($b->gt($today)) {
            return null;
        }
        if (in_array($precision, ['year', 'about'], true)) {
            return $today->year - $b->year;
        }

        return (int) $b->diffInYears($today);
    }

    // ---------------------------------------------------------------- figures

    /** @return array<string, int> */
    private function population(): array
    {
        $gender = $this->living()->groupBy('g')->selectRaw("CASE WHEN LOWER(gender) IN ('male', 'm') THEN 'male' WHEN LOWER(gender) IN ('female', 'f') THEN 'female' ELSE 'unknown' END AS g, COUNT(*) AS n")
            ->pluck('n', 'g');

        return [
            'total' => $this->family()->count(),
            'living' => $this->living()->count(),
            'late' => $this->late()->count(),
            'exited' => $this->family()->where('status', 'exited')->whereNull('date_of_death')->count(), // total = living + late + exited
            'male' => (int) ($gender['male'] ?? 0),
            'female' => (int) ($gender['female'] ?? 0),
            'unknown_gender' => (int) ($gender['unknown'] ?? 0),
        ];
    }

    /** @return list<array{n: int, count: int}> */
    private function generations(): array
    {
        return $this->family()->whereNotNull('generation')->groupBy('generation')->orderBy('generation')->selectRaw('generation, COUNT(*) AS n')->get()
            ->map(fn ($r) => ['n' => (int) $r->generation, 'count' => (int) $r->n])->all();
    }

    /** @return list<array{id: int, name: string, count: int, living: int}> */
    private function branches(): array
    {
        $all = $this->family()->whereNotNull('branch_id')->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) AS n')->pluck('n', 'branch_id');
        $living = $this->living()->whereNotNull('branch_id')->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) AS n')->pluck('n', 'branch_id');

        return DB::table('kin_branches')->where('company_id', $this->cid)->where('is_deleted', 0)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->name, 'count' => (int) ($all[$b->id] ?? 0), 'living' => (int) ($living[$b->id] ?? 0)])->all();
    }

    /** @return list<array{key: string, count: int}> every band, in order, then unknown */
    private function ageBands(): array
    {
        $counts = array_fill_keys([...array_keys(self::AGE_BANDS), 'unknown'], 0);
        foreach ($this->living()->get(['date_of_birth', 'birth_date_precision']) as $r) {
            $age = self::age($r->date_of_birth, $r->birth_date_precision, $this->today);
            $key = 'unknown';
            if ($age !== null) {
                foreach (self::AGE_BANDS as $band => [$lo, $hi]) {
                    if ($age >= $lo && $age <= $hi) {
                        $key = $band;
                        break;
                    }
                }
            }
            $counts[$key]++;
        }

        return array_map(fn ($k) => ['key' => $k, 'count' => $counts[$k]], array_keys($counts));
    }

    /** What people write, to one of the fixed keys the phone translates. */
    public static function educationKey(?string $level): string
    {
        $l = mb_strtolower(trim((string) $level));

        return match (true) {
            $l === '' => 'unknown',
            in_array($l, self::EDUCATION, true) => $l,
            (bool) preg_match('/ph\.?d|doctor/', $l) => 'phd',
            (bool) preg_match('/master|msc|mba|\bma\b/', $l) => 'masters',
            (bool) preg_match('/degree|bachelor|graduate|university|bsc|\bba\b/', $l) => 'degree',
            str_contains($l, 'diploma') => 'diploma',
            str_contains($l, 'certificate') => 'certificate',
            (bool) preg_match('/secondary|o[ -]?level|a[ -]?level|high school|s\.?\s?[1-6]\b|uce|uace/', $l) => 'secondary',
            (bool) preg_match('/primary|p\.?\s?[1-7]\b|ple/', $l) => 'primary',
            (bool) preg_match('/^(none|no school|never)/', $l) => 'none',
            default => 'unknown',
        };
    }

    /** @return list<array{key: string, count: int}> every key, in order */
    private function education(): array
    {
        $counts = array_fill_keys(self::EDUCATION, 0);
        foreach ($this->living()->groupBy('education_level')->selectRaw('education_level, COUNT(*) AS n')->get() as $r) {
            $counts[self::educationKey($r->education_level)] += (int) $r->n;
        }

        return array_map(fn ($k) => ['key' => $k, 'count' => $counts[$k]], self::EDUCATION);
    }

    /** @return list<array{name: string, count: int}> the living by a written column, most first (top 20) */
    private function countOf(string $column): array
    {
        return $this->living()->whereNotNull($column)->whereRaw("TRIM({$column}) != ''")->groupByRaw("TRIM({$column})")
            ->selectRaw("TRIM({$column}) AS name, COUNT(*) AS n")->orderByDesc('n')->orderBy('name')->limit(20)->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->n])->all();
    }

    /** @return array{today: list<array<string, mixed>>, next_30_days: list<array<string, mixed>>} */
    private function birthdays(): array
    {
        $today = [];
        $soon = [];
        $rows = $this->living()->whereNotNull('date_of_birth')->where(fn ($q) => $q->whereNull('birth_date_precision')->orWhere('birth_date_precision', 'exact'))
            ->get(['id', 'first_name', 'other_names', 'last_name', 'date_of_birth']);
        foreach ($rows as $r) {
            $b = CarbonImmutable::parse($r->date_of_birth);
            [$next, $days] = $this->nextAnniversary($b);
            if ($days > 30 || $b->gt($this->today)) {
                continue;
            }
            $item = ['id' => (int) $r->id, 'name' => self::name($r), 'date' => $b->format('m-d'), 'turning' => $next->year - $b->year, 'days' => $days];
            $days === 0 ? $today[] = $item : $soon[] = $item;
        }
        usort($soon, fn ($a, $b) => [$a['days'], $a['name']] <=> [$b['days'], $b['name']]);
        $strip = fn (array $l) => array_map(fn ($i) => array_diff_key($i, ['days' => 1]), $l);

        return ['today' => $strip($today), 'next_30_days' => $strip($soon)];
    }

    /** @return list<array{id: int, name: string, date: string, years: int}> the anniversaries of the departed, today to 30 days on */
    private function memorials(): array
    {
        $out = [];
        foreach ($this->family()->whereNotNull('date_of_death')->get(['id', 'first_name', 'other_names', 'last_name', 'date_of_death']) as $r) {
            $d = CarbonImmutable::parse($r->date_of_death);
            [$next, $days] = $this->nextAnniversary($d);
            if ($days > 30 || $next->year - $d->year < 1) {
                continue;
            }
            $out[] = ['id' => (int) $r->id, 'name' => self::name($r), 'date' => $d->format('m-d'), 'years' => $next->year - $d->year, 'days' => $days];
        }
        usort($out, fn ($a, $b) => [$a['days'], $a['name']] <=> [$b['days'], $b['name']]);

        return array_map(fn ($i) => array_diff_key($i, ['days' => 1]), $out);
    }

    /** The next time a day comes round (today counts), and in how many days. 29 February falls on the 28th in other years. @return array{0: CarbonImmutable, 1: int} */
    private function nextAnniversary(CarbonImmutable $d): array
    {
        $on = fn (int $year) => CarbonImmutable::create($year, $d->month, min($d->day, CarbonImmutable::create($year, $d->month, 1)->daysInMonth));
        $next = $on($this->today->year);
        if ($next->lt($this->today)) {
            $next = $on($this->today->year + 1);
        }

        return [$next, (int) $this->today->diffInDays($next)];
    }

    /** @return list<array{id: int, name: string, added_at: ?string}> */
    private function recent(): array
    {
        return $this->family()->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get(['id', 'first_name', 'other_names', 'last_name', 'created_at'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => self::name($r), 'added_at' => $r->created_at ? CarbonImmutable::parse($r->created_at)->toIso8601String() : null])->all();
    }

    /** @return array<string, mixed> */
    private function facts(): array
    {
        $ages = $this->living()->whereNotNull('date_of_birth')->get(['id', 'first_name', 'other_names', 'last_name', 'date_of_birth', 'birth_date_precision'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => self::name($r), 'age' => self::age($r->date_of_birth, $r->birth_date_precision, $this->today), 'born' => $r->date_of_birth])
            ->filter(fn ($r) => $r['age'] !== null)->values();
        $eldest = $ages->sortBy([['born', 'asc'], ['id', 'asc']])->first();
        $youngest = $ages->sortBy([['born', 'desc'], ['id', 'asc']])->first();

        $children = DB::table('kin_links')->where('kin_links.company_id', $this->cid)->where('kin_links.is_deleted', 0)
            ->join('sacco_members as c', 'c.id', '=', 'kin_links.child_id')->where('c.is_deleted', 0)->whereNull('c.merged_into_id')
            ->groupBy('kin_links.parent_id')->selectRaw('kin_links.parent_id AS id, COUNT(DISTINCT kin_links.child_id) AS n')->get();
        $top = $children->sortBy([['n', 'desc'], ['id', 'asc']])->first();
        $topPerson = $top ? $this->family()->where('id', $top->id)->first(['id', 'first_name', 'other_names', 'last_name']) : null;

        return [
            'eldest_living' => $eldest ? array_diff_key($eldest, ['born' => 1]) : null,
            'youngest' => $youngest ? array_diff_key($youngest, ['born' => 1]) : null,
            'most_children' => $topPerson ? ['id' => (int) $topPerson->id, 'name' => self::name($topPerson), 'children' => (int) $top->n] : null,
            'average_children' => $children->isEmpty() ? 0.0 : round($children->avg('n'), 1), // over the people who have children
            'average_age_living' => $ages->isEmpty() ? 0.0 : round($ages->avg('age'), 1),
            'with_phone' => $this->living()->whereNotNull('phone_e164')->where('phone_e164', '!=', '')->count(),
            'missing_birth_date' => $this->living()->whereNull('date_of_birth')->count(),
        ];
    }

    /** @return array<string, int> the active contributions, summed from their payments */
    private function dues(): array
    {
        $svc = app(DuesService::class);
        $t = ['active' => 0, 'expected_minor' => 0, 'collected_minor' => 0, 'balance_minor' => 0];
        $owes = [];
        foreach (SaccoDuesProgramme::withoutGlobalScopes()->where('company_id', $this->cid)->where('status', 'active')->get() as $p) {
            $s = $svc->standing($p);
            $t['active']++;
            $t['expected_minor'] += $s['expected'];
            $t['collected_minor'] += $s['collected'];
            $t['balance_minor'] += $s['balance'];
            if ($p->amount_mode === 'any') {
                continue; // no one owes an open-ended collection: it counts in the totals, not in who owes
            }
            foreach ($s['people'] as $r) {
                if ($r['status'] !== 'exempt') {
                    $owes[$r['member_id']] = ($owes[$r['member_id']] ?? false) || $r['status'] === 'owing' || $r['status'] === 'part';
                }
            }
        }
        $month = [$this->today->startOfMonth()->toDateString(), $this->today->endOfMonth()->toDateString()];

        return $t + [
            'paid_people' => count(array_filter($owes, fn ($o) => ! $o)),
            'owing_people' => count(array_filter($owes)),
            'collected_this_month_minor' => (int) DB::table('sacco_dues_payments')->where('company_id', $this->cid)->whereBetween('paid_on', $month)->sum('amount_minor'),
        ];
    }

    /** @return array{enabled: bool, savings_minor: int, loans_out_minor: int, members: int} */
    private function purse(): array
    {
        $s = KinPurse::status($this->company);

        return ['enabled' => $s['enabled'], 'savings_minor' => $s['savings_minor'], 'loans_out_minor' => $s['loans_out_minor'], 'members' => $s['members']];
    }

    /** @return ?array{id: int, title: string, published_at: ?string, pinned: bool} the pinned one, else the newest */
    private function announcement(): ?array
    {
        $a = DB::table('kin_announcements')->where('company_id', $this->cid)->whereNull('deleted_at')->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByDesc('pinned')->orderByDesc('published_at')->orderByDesc('id')->first(['id', 'title', 'published_at', 'pinned', 'author_user_id']);
        if (! $a) {
            return null;
        }
        $u = $a->author_user_id ? DB::table('admin_users')->where('id', $a->author_user_id)->first(['name', 'first_name', 'last_name']) : null;
        $author = $u ? trim((string) ($u->name ?: $u->first_name.' '.$u->last_name)) : null;

        return ['id' => (int) $a->id, 'title' => $a->title, 'published_at' => CarbonImmutable::parse($a->published_at)->toIso8601String(), 'pinned' => (bool) $a->pinned,
            'author_name' => $author !== '' ? $author : null];
    }

    /** The next planned gathering (from now on), with how many said they are going. @return array<string, mixed>|null */
    private function nextEvent(): ?array
    {
        $e = DB::table('kin_events')->where('company_id', $this->cid)->where('status', 'planned')->where('starts_at', '>=', now())
            ->orderBy('starts_at')->orderBy('id')->first(['id', 'title', 'kind', 'starts_at', 'venue']);
        if (! $e) {
            return null;
        }

        return ['id' => (int) $e->id, 'title' => $e->title, 'kind' => $e->kind, 'starts_at' => CarbonImmutable::parse($e->starts_at)->toIso8601String(), 'venue' => $e->venue,
            'going' => (int) DB::table('kin_event_people')->where('event_id', $e->id)->where('rsvp', 'going')->count(),
            'my_rsvp' => $this->meId === null ? null : DB::table('kin_event_people')->where('event_id', $e->id)->where('member_id', $this->meId)->value('rsvp')];
    }
}

<?php

namespace App\Services\Unity;

use App\Models\Company;
use App\Support\LocalDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The home briefing (GET unity/insights): the state of the group in one answer. Every number is defined in unitydesk
 * docs/13-home-stats.md, and each count matches a members filter (MemberFilters) so a tap lists exactly the members counted.
 * Aggregates run in SQL: a fixed number of queries whatever the group's size.
 */
final class Insights
{
    public const HEALTH_WEIGHTS = ['collection' => 35, 'attendance' => 25, 'growth' => 20, 'completeness' => 20];

    /** The fields completeness counts, for each current member. */
    public const COMPLETENESS_FIELDS = ['phone_e164', 'date_of_birth', 'gender', 'district', 'photo_file_id', 'next_of_kin_name'];

    /** @return array<string, mixed> */
    public static function build(Company $company, bool $finance): array
    {
        $cid = (int) $company->id;
        Memberships::refreshStanding($cid);
        $today = LocalDate::today($cid);
        $dues = self::dues($cid, $today);
        $attendance90 = self::attendanceByChapter($cid, $today->copy()->subDays(89), $today);
        $growth = self::growth($cid, $today);
        $completeness = self::completeness($cid);
        $current = (int) MemberFilters::current($cid)->count();

        return [
            'as_of' => $today->toDateString(),
            'currency' => (string) ($company->currency ?: 'UGX'),
            'headline' => [
                'active' => (int) MemberFilters::base($cid)->where('status', 'active')->count(),
                'new_this_month' => (int) MemberFilters::base($cid)->whereNotIn('status', ['rejected', 'applicant'])
                    ->whereRaw('COALESCE(member_since, joined_on) BETWEEN ? AND ?', [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()])->count(),
                'renewals_due_30' => (int) Memberships::expiring($cid, 30)->count(),
                'collected_ytd_minor' => $finance ? $dues['collected'] : null,
                'expected_ytd_minor' => $finance ? $dues['expected'] : null,
                'collection_pct' => $finance ? $dues['pct'] : null,
                'fund_balance_minor' => $finance ? Welfare::balance($cid) : null,
            ],
            'growth' => $growth['months'],
            'collections' => $finance ? self::collections($cid, $today) : null,
            'status_split' => self::statusSplit($cid),
            'chapters' => self::chapters($cid, $today, $attendance90),
            'profile' => self::profile($cid, $today, $current),
            'attention' => self::attention($cid, $today),
            'this_week' => self::thisWeek($cid, $today),
            'health' => self::health($cid, $today, $dues['pct'], $attendance90, $growth['net_pct'], $completeness),
        ];
    }

    /**
     * Dues this year so far. expected: the yearly dues of every current member in a paying category (fee x 12 / period
     * months) times the share of the year gone (day of year / days in year). collected: what members paid towards
     * subscriptions this year (paid_minor of paid or pending periods, by the day paid, else the period's start).
     *
     * @return array{expected: int, collected: int, pct: ?float}
     */
    public static function dues(int $cid, Carbon $today): array
    {
        $yearly = (float) DB::table('sacco_members AS s')->join('unity_categories AS c', 'c.id', '=', 's.category_id')
            ->where('s.company_id', $cid)->where('s.is_deleted', 0)->whereNull('s.merged_into_id')->whereIn('s.status', UnityMembers::CURRENT)
            ->where('c.period_months', '>', 0)->where('c.fee_minor', '>', 0)
            ->sum(DB::raw('c.fee_minor * 12 / c.period_months'));
        $share = $today->dayOfYear / ($today->isLeapYear() ? 366 : 365);
        $expected = (int) round($yearly * $share);
        $collected = (int) self::paidSubscriptions($cid)->whereRaw('COALESCE(paid_on, starts_on) BETWEEN ? AND ?', [$today->copy()->startOfYear()->toDateString(), $today->toDateString()])
            ->sum('paid_minor');

        return ['expected' => $expected, 'collected' => $collected, 'pct' => $expected > 0 ? round($collected * 100 / $expected, 1) : null];
    }

    private static function paidSubscriptions(int $cid): \Illuminate\Database\Query\Builder
    {
        return DB::table('unity_subscriptions')->where('company_id', $cid)->where('is_deleted', 0)->whereIn('status', ['paid', 'pending'])->where('paid_minor', '>', 0);
    }

    /** Dues paid by month (the same money as collected), this year and last. @return array{months: list<string>, this_year: list<int>, last_year: list<int>} */
    public static function collections(int $cid, Carbon $today): array
    {
        $y = (int) $today->year;
        $rows = self::paidSubscriptions($cid)->whereRaw('COALESCE(paid_on, starts_on) BETWEEN ? AND ?', [($y - 1).'-01-01', $today->toDateString()])
            ->selectRaw('YEAR(COALESCE(paid_on, starts_on)) AS y, MONTH(COALESCE(paid_on, starts_on)) AS m, SUM(paid_minor) AS total')->groupBy('y', 'm')->get();
        $thisY = array_fill(0, 12, 0);
        $last = array_fill(0, 12, 0);
        foreach ($rows as $r) {
            if ((int) $r->y === $y) {
                $thisY[(int) $r->m - 1] = (int) $r->total;
            } else {
                $last[(int) $r->m - 1] = (int) $r->total;
            }
        }

        return ['months' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], 'this_year' => $thisY, 'last_year' => $last];
    }

    /**
     * The last 12 months (this one last): who joined (member_since, else joined_on; not applicants or rejected) and who left
     * (exited or deceased, on the day the status changed). net_pct: (joined - left) over 12 months, as a share of the
     * members there were before (current now - joined + left); null without a base.
     *
     * @return array{months: list<array{month: string, joined: int, exited: int}>, net_pct: ?float}
     */
    public static function growth(int $cid, Carbon $today): array
    {
        $from = $today->copy()->startOfMonth()->subMonths(11);
        $joined = MemberFilters::base($cid)->whereNotIn('status', ['rejected', 'applicant'])
            ->whereRaw('COALESCE(member_since, joined_on) BETWEEN ? AND ?', [$from->toDateString(), $today->toDateString()])
            ->selectRaw("DATE_FORMAT(COALESCE(member_since, joined_on), '%Y-%m') AS ym, COUNT(*) AS n")->groupBy('ym')->pluck('n', 'ym');
        $left = MemberFilters::base($cid)->whereIn('status', ['exited', 'deceased'])
            ->whereBetween('status_changed_on', [$from->toDateString(), $today->toDateString()])
            ->selectRaw("DATE_FORMAT(status_changed_on, '%Y-%m') AS ym, COUNT(*) AS n")->groupBy('ym')->pluck('n', 'ym');
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $ym = $from->copy()->addMonths($i)->format('Y-m');
            $months[] = ['month' => $ym, 'joined' => (int) ($joined[$ym] ?? 0), 'exited' => (int) ($left[$ym] ?? 0)];
        }
        // The health part looks at a full year back from today, not calendar months.
        $yearAgo = $today->copy()->subYear()->addDay()->toDateString();
        $j = (int) MemberFilters::base($cid)->whereNotIn('status', ['rejected', 'applicant'])
            ->whereRaw('COALESCE(member_since, joined_on) BETWEEN ? AND ?', [$yearAgo, $today->toDateString()])->count();
        $l = (int) MemberFilters::base($cid)->whereIn('status', ['exited', 'deceased'])->whereBetween('status_changed_on', [$yearAgo, $today->toDateString()])->count();
        $base = (int) MemberFilters::current($cid)->count() - $j + $l;

        return ['months' => $months, 'net_pct' => $base > 0 ? round(($j - $l) * 100 / $base, 1) : null];
    }

    /** @return array<string, int> */
    public static function statusSplit(int $cid): array
    {
        $by = MemberFilters::base($cid)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return ['applicant' => (int) ($by['applicant'] ?? 0), 'active' => (int) ($by['active'] ?? 0), 'lapsed' => (int) ($by['dormant'] ?? 0),
            'suspended' => (int) ($by['suspended'] ?? 0), 'exited' => (int) ($by['exited'] ?? 0), 'deceased' => (int) ($by['deceased'] ?? 0)];
    }

    /**
     * Attendance of held chapter meetings between two days: present marks over all marks, per chapter.
     *
     * @return array<int, array{present: int, marked: int, held: int}>
     */
    public static function attendanceByChapter(int $cid, Carbon $from, Carbon $to, bool $wholeGroupToo = false): array
    {
        $rows = DB::table('unity_meetings AS m')->join('unity_attendance AS a', fn ($j) => $j->on('a.meeting_id', '=', 'm.id')->where('a.is_deleted', 0))
            ->where('m.company_id', $cid)->where('m.is_deleted', 0)->where('m.status', 'held')
            ->whereBetween('m.starts_at', self::utcDays($cid, $from, $to))
            ->when(! $wholeGroupToo, fn ($q) => $q->whereNotNull('m.chapter_id'))
            ->selectRaw('COALESCE(m.chapter_id, 0) AS chapter, SUM(a.present) AS present, COUNT(a.id) AS marked, COUNT(DISTINCT m.id) AS held')
            ->groupBy('chapter')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->chapter] = ['present' => (int) $r->present, 'marked' => (int) $r->marked, 'held' => (int) $r->held];
        }

        return $out;
    }

    /** The group's calendar days [from, to] as UTC bounds for starts_at (stored in UTC). @return array{0: string, 1: string} */
    public static function utcDays(int $cid, Carbon $from, Carbon $to): array
    {
        $tz = LocalDate::timezone($cid);

        return [Carbon::parse($from->toDateString().' 00:00:00', $tz)->utc()->toDateTimeString(), Carbon::parse($to->toDateString().' 23:59:59', $tz)->utc()->toDateTimeString()];
    }

    private static function pct(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    /** The SQL that says a sacco_members row (alias-free) is paid up today. */
    public static function paidUpSql(Carbon $today): array
    {
        return ["(standing = 'life' OR (standing IN ('good', 'lapsed') AND membership_ends_on >= ?))", [$today->toDateString()]];
    }

    /**
     * Each chapter (not regions): its current members, the share of them paid up, and attendance over the last 90 days.
     *
     * @param  array<int, array{present: int, marked: int, held: int}>  $attendance
     * @return list<array<string, mixed>>
     */
    public static function chapters(int $cid, Carbon $today, array $attendance): array
    {
        [$paidSql, $paidArgs] = self::paidUpSql($today);
        $counts = MemberFilters::current($cid)->whereNotNull('chapter_id')
            ->selectRaw("chapter_id, COUNT(*) AS n, SUM(CASE WHEN {$paidSql} THEN 1 ELSE 0 END) AS paid", $paidArgs)->groupBy('chapter_id')->get()->keyBy('chapter_id');
        $places = DB::table('unity_chapters')->where('company_id', $cid)->where('is_deleted', 0)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'kind', 'parent_id', 'code']);
        $regions = $places->where('kind', 'region')->keyBy('id');
        $out = [];
        foreach ($places->where('kind', '!=', 'region') as $c) {
            $n = (int) ($counts[$c->id]->n ?? 0);
            $a = $attendance[(int) $c->id] ?? null;
            $out[] = ['id' => (int) $c->id, 'name' => $c->name, 'code' => $c->code, 'region' => $c->parent_id ? ($regions[$c->parent_id]->name ?? null) : null,
                'members' => $n, 'paid_up_pct' => self::pct((int) ($counts[$c->id]->paid ?? 0), $n) ?? 0.0,
                'attendance_pct' => $a ? self::pct($a['present'], $a['marked']) : null];
        }

        return $out;
    }

    /** The eight most common occupations of current members, by count then name. @return list<array{key: string, label: string, count: int}> */
    public static function topOccupations(int $cid): array
    {
        return MemberFilters::current($cid)->whereNotNull('occupation')->whereRaw("TRIM(occupation) <> ''")
            ->selectRaw('LOWER(TRIM(occupation)) AS k, MIN(TRIM(occupation)) AS label, COUNT(*) AS n')->groupBy('k')
            ->orderByDesc('n')->orderBy('k')->limit(8)->get()
            ->map(fn ($r) => ['key' => (string) $r->k, 'label' => ucfirst((string) $r->label), 'count' => (int) $r->n])->all();
    }

    /** @return array<string, mixed> */
    public static function profile(int $cid, Carbon $today, int $current): array
    {
        $d = $today->toDateString();
        $cur = fn () => MemberFilters::current($cid);

        $ageRow = $cur()->selectRaw("
            SUM(date_of_birth IS NULL) AS unknown_n,
            SUM(TIMESTAMPDIFF(YEAR, date_of_birth, ?) < 18) AS u18,
            SUM(TIMESTAMPDIFF(YEAR, date_of_birth, ?) BETWEEN 18 AND 25) AS a18_25,
            SUM(TIMESTAMPDIFF(YEAR, date_of_birth, ?) BETWEEN 26 AND 35) AS a26_35,
            SUM(TIMESTAMPDIFF(YEAR, date_of_birth, ?) BETWEEN 36 AND 45) AS a36_45,
            SUM(TIMESTAMPDIFF(YEAR, date_of_birth, ?) BETWEEN 46 AND 60) AS a46_60,
            SUM(TIMESTAMPDIFF(YEAR, date_of_birth, ?) > 60) AS a60p", array_fill(0, 6, $d))->first();
        $age = [['key' => 'u18', 'count' => (int) $ageRow->u18], ['key' => '18_25', 'count' => (int) $ageRow->a18_25], ['key' => '26_35', 'count' => (int) $ageRow->a26_35],
            ['key' => '36_45', 'count' => (int) $ageRow->a36_45], ['key' => '46_60', 'count' => (int) $ageRow->a46_60], ['key' => '60p', 'count' => (int) $ageRow->a60p],
            ['key' => 'unknown', 'count' => (int) $ageRow->unknown_n]];

        $g = $cur()->selectRaw("SUM(gender = 'male') AS male, SUM(gender = 'female') AS female")->first();
        $gender = [['key' => 'female', 'count' => (int) $g->female], ['key' => 'male', 'count' => (int) $g->male],
            ['key' => 'unknown', 'count' => $current - (int) $g->female - (int) $g->male]];

        $eduBy = $cur()->whereIn('education_level', UnityMembers::EDUCATION)->selectRaw('education_level, COUNT(*) AS n')->groupBy('education_level')->pluck('n', 'education_level');
        $education = [];
        $known = 0;
        foreach (UnityMembers::EDUCATION as $k) {
            $education[] = ['key' => $k, 'count' => (int) ($eduBy[$k] ?? 0)];
            $known += (int) ($eduBy[$k] ?? 0);
        }
        $education[] = ['key' => 'unknown', 'count' => $current - $known];

        $top = self::topOccupations($cid);
        $withOcc = (int) $cur()->whereNotNull('occupation')->whereRaw("TRIM(occupation) <> ''")->count();
        $occupation = $top;
        $occupation[] = ['key' => 'other', 'label' => 'Other', 'count' => $withOcc - array_sum(array_column($top, 'count'))];
        $occupation[] = ['key' => 'unknown', 'label' => 'Not recorded', 'count' => $current - $withOcc];

        $places = $cur()->whereNotNull('district')->whereRaw("TRIM(district) <> ''")
            ->selectRaw("LOWER(TRIM(district)) AS dk, MIN(TRIM(district)) AS dn, LOWER(TRIM(COALESCE(subcounty, ''))) AS sk, MIN(TRIM(subcounty)) AS sn, COUNT(*) AS n")
            ->groupBy('dk', 'sk')->get();
        $districts = [];
        foreach ($places->groupBy('dk') as $rows) {
            $subs = $rows->filter(fn ($r) => $r->sk !== '')->sortBy([['n', 'desc'], ['sn', 'asc']])
                ->map(fn ($r) => ['name' => $r->sn, 'count' => (int) $r->n])->values()->all();
            $districts[] = ['name' => $rows->first()->dn, 'count' => (int) $rows->sum('n'), 'subcounties' => $subs];
        }
        usort($districts, fn ($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        $t = $cur()->selectRaw('
            SUM(member_since IS NULL) AS unknown_n,
            SUM(TIMESTAMPDIFF(YEAR, member_since, ?) < 1) AS lt1,
            SUM(TIMESTAMPDIFF(YEAR, member_since, ?) BETWEEN 1 AND 2) AS t1_3,
            SUM(TIMESTAMPDIFF(YEAR, member_since, ?) BETWEEN 3 AND 4) AS t3_5,
            SUM(TIMESTAMPDIFF(YEAR, member_since, ?) BETWEEN 5 AND 9) AS t5_10,
            SUM(TIMESTAMPDIFF(YEAR, member_since, ?) >= 10) AS t10p', array_fill(0, 5, $d))->first();
        $tenure = [['key' => 'lt1', 'count' => (int) $t->lt1], ['key' => '1_3', 'count' => (int) $t->t1_3], ['key' => '3_5', 'count' => (int) $t->t3_5],
            ['key' => '5_10', 'count' => (int) $t->t5_10], ['key' => '10p', 'count' => (int) $t->t10p], ['key' => 'unknown', 'count' => (int) $t->unknown_n]];

        $longest = $cur()->whereNotNull('member_since')->orderBy('member_since')->orderBy('id')->limit(5)
            ->get(['id', 'first_name', 'last_name', 'other_names', 'member_since'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => self::name($r), 'member_since' => (string) $r->member_since,
                'years' => Carbon::parse((string) $r->member_since)->diffInYears($today)])->all();

        $rec = $cur()->selectRaw("SUM(date_of_birth IS NOT NULL) AS dob, SUM(gender IS NOT NULL AND gender <> '') AS gender,
            SUM(education_level IS NOT NULL AND education_level <> '') AS education, SUM(occupation IS NOT NULL AND TRIM(occupation) <> '') AS occupation,
            SUM(district IS NOT NULL AND TRIM(district) <> '') AS district, SUM(member_since IS NOT NULL) AS since")->first();

        return [
            'current' => $current, 'age' => $age, 'gender' => $gender, 'education' => $education, 'occupation' => $occupation,
            'districts' => $districts, 'tenure' => $tenure, 'longest_serving' => $longest,
            'recorded' => ['date_of_birth' => (int) $rec->dob, 'gender' => (int) $rec->gender, 'education' => (int) $rec->education,
                'occupation' => (int) $rec->occupation, 'district' => (int) $rec->district, 'member_since' => (int) $rec->since],
        ];
    }

    /** @return array<string, int> */
    public static function attention(int $cid, Carbon $today): array
    {
        $out = [];
        foreach (MemberFilters::ATTENTION as $key) {
            $q = MemberFilters::base($cid);
            MemberFilters::attention($q, $cid, $key, $today);
            $out[$key] = (int) $q->count();
        }

        return $out;
    }

    /**
     * Members of a chapter that held at least three meetings who were present at none of its last three held meetings, and
     * who were members before the first of the three. Whole-group meetings do not count.
     *
     * @return list<int>
     */
    public static function missedThree(int $cid): array
    {
        $held = DB::table('unity_meetings')->where('company_id', $cid)->where('is_deleted', 0)->where('status', 'held')->whereNotNull('chapter_id')
            ->orderBy('chapter_id')->orderByDesc('starts_at')->orderByDesc('id')->get(['id', 'chapter_id', 'starts_at']);
        $windows = [];
        foreach ($held->groupBy('chapter_id') as $chapter => $rows) {
            if ($rows->count() >= 3) {
                $last = $rows->take(3);
                $windows[(int) $chapter] = ['ids' => $last->pluck('id')->map(fn ($i) => (int) $i)->all(), 'from' => substr((string) $last->last()->starts_at, 0, 10)];
            }
        }
        if ($windows === []) {
            return [];
        }
        $ids = array_merge(...array_column($windows, 'ids'));
        $present = DB::table('unity_attendance')->where('company_id', $cid)->where('is_deleted', 0)->where('present', 1)->whereIn('meeting_id', $ids)
            ->select('member_id');

        return MemberFilters::current($cid)->where(function ($w) use ($windows) {
            foreach ($windows as $chapter => $win) {
                $w->orWhere(fn ($c) => $c->where('chapter_id', $chapter)->where(fn ($s) => $s->whereNull('member_since')->orWhere('member_since', '<=', $win['from'])));
            }
        })->whereNotIn('id', $present)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** Members with an open pledge past its due day (the pledge's, else the drive's) and not fully paid. */
    public static function overduePledgers(int $cid, Carbon $today): \Illuminate\Database\Query\Builder
    {
        return DB::table('unity_pledges AS p')->join('unity_drives AS d', 'd.id', '=', 'p.drive_id')
            ->where('p.company_id', $cid)->where('p.is_deleted', 0)->where('d.is_deleted', 0)->where('p.status', 'open')
            ->whereColumn('p.paid_minor', '<', 'p.pledged_minor')->whereRaw('COALESCE(p.due_on, d.due_on) < ?', [$today->toDateString()])
            ->select('p.member_id');
    }

    /** @return array<string, mixed> */
    public static function thisWeek(int $cid, Carbon $today): array
    {
        $occurs = function (string $date) use ($today): Carbon {
            $d = Carbon::parse($date);
            $day = Carbon::create($today->year, $d->month, min($d->day, Carbon::create($today->year, $d->month, 1)->daysInMonth))->startOfDay();
            if ($day->lt($today->copy()->startOfDay())) {
                $day = Carbon::create($today->year + 1, $d->month, min($d->day, Carbon::create($today->year + 1, $d->month, 1)->daysInMonth))->startOfDay();
            }

            return $day;
        };
        $q = MemberFilters::current($cid);
        MemberFilters::dayInWeek($q, 'date_of_birth', $today);
        $birthdays = $q->get(['id', 'first_name', 'last_name', 'other_names', 'date_of_birth', 'phone_e164'])
            ->map(function ($r) use ($occurs) {
                $on = $occurs((string) $r->date_of_birth);

                return ['id' => (int) $r->id, 'name' => self::name($r), 'date' => $on->toDateString(), 'turns' => $on->year - (int) substr((string) $r->date_of_birth, 0, 4), 'phone' => $r->phone_e164];
            })->sortBy([['date', 'asc'], ['name', 'asc']])->values()->all();

        $q = MemberFilters::current($cid);
        MemberFilters::dayInWeek($q, 'member_since', $today);
        $anniversaries = $q->get(['id', 'first_name', 'last_name', 'other_names', 'member_since', 'phone_e164'])
            ->map(function ($r) use ($occurs) {
                $on = $occurs((string) $r->member_since);

                return ['id' => (int) $r->id, 'name' => self::name($r), 'date' => $on->toDateString(), 'years' => $on->year - (int) substr((string) $r->member_since, 0, 4), 'phone' => $r->phone_e164];
            })->filter(fn ($a) => $a['years'] >= 1)->sortBy([['date', 'asc'], ['name', 'asc']])->values()->all();

        $now = now(); // starts_at is stored in UTC
        $meeting = DB::table('unity_meetings')->where('company_id', $cid)->where('is_deleted', 0)->where('status', 'scheduled')
            ->where('starts_at', '>=', $now->toDateTimeString())->orderBy('starts_at')->orderBy('id')->first();
        $ballot = DB::table('unity_ballots')->where('company_id', $cid)->where('is_deleted', 0)->where('status', 'open')
            ->orderByRaw('closes_at IS NULL')->orderBy('closes_at')->orderBy('id')->first();

        return [
            'birthdays' => $birthdays,
            'anniversaries' => $anniversaries,
            'next_meeting' => $meeting === null ? null : ['id' => (int) $meeting->id, 'title' => $meeting->title, 'kind' => $meeting->kind,
                'starts_at' => Meetings::iso($cid, $meeting->starts_at), 'venue' => $meeting->venue,
                'rsvp_yes' => (int) DB::table('unity_attendance')->where('meeting_id', $meeting->id)->where('is_deleted', 0)->where('rsvp', 'yes')->count()],
            'open_ballot' => $ballot === null ? null : ['id' => (int) $ballot->id, 'kind' => $ballot->kind, 'title' => $ballot->title,
                'closes_at' => $ballot->closes_at ? Meetings::iso($cid, $ballot->closes_at) : null,
                'voted' => (int) DB::table('unity_votes')->where('ballot_id', $ballot->id)->where('is_deleted', 0)->distinct()->count('member_id'),
                'eligible' => Ballots::eligible($cid, $ballot)->count()],
        ];
    }

    /** completeness: the share of the six fields filled in, over all current members; null with none. */
    public static function completeness(int $cid): ?float
    {
        $parts = [];
        foreach (self::COMPLETENESS_FIELDS as $f) {
            $parts[] = $f === 'photo_file_id' || $f === 'date_of_birth' ? "SUM({$f} IS NOT NULL)" : "SUM({$f} IS NOT NULL AND TRIM({$f}) <> '')";
        }
        $r = MemberFilters::current($cid)->selectRaw('COUNT(*) AS n, '.implode(' + ', $parts).' AS filled')->first();

        return (int) $r->n > 0 ? round((int) $r->filled * 100 / ((int) $r->n * count(self::COMPLETENESS_FIELDS)), 1) : null;
    }

    /**
     * The health score (0-100) from four parts. A part's points = weight x its score (0-1): collection and completeness
     * score their percentage (collection capped at 100); attendance its percentage over held meetings of the last 90 days
     * (chapter and whole-group); growth maps -10 % to 0 and +10 % to 1 (straight line between, capped). A part with no data
     * has null value and points, and the score is the points over the weights that have data.
     *
     * @param  array<int, array{present: int, marked: int, held: int}>  $chapterAttendance
     * @return array<string, mixed>
     */
    public static function health(int $cid, Carbon $today, ?float $collectionPct, array $chapterAttendance, ?float $growthPct, ?float $completeness): array
    {
        $all = self::attendanceByChapter($cid, $today->copy()->subDays(89), $today, true);
        $attendancePct = self::pct(array_sum(array_column($all, 'present')), array_sum(array_column($all, 'marked')));
        $values = [
            'collection' => [$collectionPct, $collectionPct === null ? null : min(100.0, $collectionPct) / 100],
            'attendance' => [$attendancePct, $attendancePct === null ? null : $attendancePct / 100],
            'growth' => [$growthPct, $growthPct === null ? null : max(0.0, min(1.0, ($growthPct + 10) / 20))],
            'completeness' => [$completeness, $completeness === null ? null : $completeness / 100],
        ];
        $parts = [];
        $points = 0.0;
        $weights = 0;
        foreach (self::HEALTH_WEIGHTS as $key => $w) {
            [$value, $score] = $values[$key];
            $p = $score === null ? null : round($w * $score, 1);
            $parts[] = ['key' => $key, 'weight' => $w, 'value_pct' => $value, 'points' => $p];
            if ($p !== null) {
                $points += $w * $score;
                $weights += $w;
            }
        }

        return [
            'score' => $weights > 0 ? (int) round($points * 100 / $weights) : null,
            'parts' => $parts,
            'top_attending_chapter' => self::topAttending($cid, $chapterAttendance),
            'most_improved_chapter' => self::mostImproved($cid, $today),
        ];
    }

    /** The chapter with the best attendance over the last 90 days (ties: more meetings held, then name). */
    private static function topAttending(int $cid, array $attendance): ?array
    {
        $names = DB::table('unity_chapters')->where('company_id', $cid)->where('is_deleted', 0)->pluck('name', 'id');
        $best = null;
        foreach ($attendance as $chapter => $a) {
            if ($a['marked'] === 0 || ! isset($names[$chapter])) {
                continue;
            }
            $row = ['id' => (int) $chapter, 'name' => $names[$chapter], 'pct' => self::pct($a['present'], $a['marked']), 'held' => $a['held']];
            if ($best === null || [$row['pct'], $row['held'], $best['name']] > [$best['pct'], $best['held'], $row['name']]) {
                $best = $row;
            }
        }

        return $best === null ? null : ['id' => $best['id'], 'name' => $best['name'], 'pct' => $best['pct']];
    }

    /** The chapter whose attendance rose most from last calendar quarter to this one (both with meetings held; a rise only). */
    private static function mostImproved(int $cid, Carbon $today): ?array
    {
        $thisQ = self::attendanceByChapter($cid, $today->copy()->firstOfQuarter(), $today);
        $lastStart = $today->copy()->firstOfQuarter()->subMonthsNoOverflow(3);
        $lastQ = self::attendanceByChapter($cid, $lastStart, $lastStart->copy()->lastOfQuarter());
        $names = DB::table('unity_chapters')->where('company_id', $cid)->where('is_deleted', 0)->pluck('name', 'id');
        $best = null;
        foreach ($thisQ as $chapter => $now) {
            $before = $lastQ[$chapter] ?? null;
            if ($before === null || $now['marked'] === 0 || $before['marked'] === 0 || ! isset($names[$chapter])) {
                continue;
            }
            $change = round($now['present'] * 100 / $now['marked'] - $before['present'] * 100 / $before['marked'], 1);
            if ($change > 0 && ($best === null || [$change, $best['name']] > [$best['change_pts'], $names[$chapter]])) {
                $best = ['id' => (int) $chapter, 'name' => $names[$chapter], 'change_pts' => $change];
            }
        }

        return $best;
    }

    public static function name(object $r): string
    {
        return trim(implode(' ', array_filter([$r->first_name ?? null, $r->other_names ?? null, $r->last_name ?? null])));
    }
}

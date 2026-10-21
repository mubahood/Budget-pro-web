<?php

namespace App\Services\Budget;

use App\Models\Company;
use App\Support\Decimal;
use App\Support\LocalTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The events book as the web portal reads it (budget-pro-new Events pages): each event with its figures, the
 * pledges of one event filtered and totalled, the pledge groups, and the "Worth a look" notes. Read straight
 * from the tables the phone and the classic screens write; rows with is_deleted = 1 are left out. Money is
 * exact decimal strings ("1500.00", App\Support\Decimal), never floats.
 *
 * - An event is a budget_programs row; it is open while its status is Active (or not set), closed otherwise
 *   (the classic form's "Inactive (Closed)"). Its day is `deadline`; days left count from today in the
 *   account's time zone (negative once it has passed).
 * - Budget = SUM(budget_items.target_amount) (unit price × quantity); spent = SUM(invested_amount).
 * - Pledged = SUM(contribution_records.amount); collected = SUM(paid_amount), which is the sum of the
 *   pledge's payments (ContributionPayments); outstanding = SUM(not_paid_amount).
 * - Collected % = collected ÷ pledged; budget % = collected ÷ budget (whole percents, rounded down, at most 100).
 * - A pledge is "not paid" with nothing paid, "part paid" with something paid but a balance left, and "paid"
 *   when fully_paid = Yes.
 */
final class EventBook
{
    /** The pledge groups the phone offers (budget-pro-mobo ContributionRecordModel): stored key => label. */
    public const GROUPS = [
        'Family' => 'Family', 'Family-2' => 'Family 2', 'Friend' => 'Friends', 'Friend-2' => 'Friends 2',
        'Workmates' => 'Workmates', 'OBs_and_OGs' => 'Old boys and girls', 'Others' => 'Others',
    ];

    /** Keys the classic web screens stored. */
    private const OLDER_GROUPS = ['Other' => 'Others', 'Member' => 'Members', 'Sponsor' => 'Sponsors', 'MTK' => 'MTK'];

    /** all | unpaid (nothing paid) | part (something paid, a balance left) | paid | owing (any balance left) */
    public const STATUSES = ['all', 'unpaid', 'part', 'paid', 'owing'];

    public const SORTS = ['balance', 'name', 'newest'];

    /** A readable name for a stored group key, as the phone shows it ("OBs_and_OGs" is "Old boys and girls"). */
    public static function groupLabel(?string $key): string
    {
        $k = trim((string) $key);
        if ($k === '') {
            return 'No group';
        }

        return self::GROUPS[$k] ?? self::OLDER_GROUPS[$k] ?? trim((string) preg_replace('/[_-]+/', ' ', $k));
    }

    /** What to store for a group someone typed: a known key or label (any case) is that key, anything else as typed. */
    public static function groupKey(?string $typed): ?string
    {
        $t = trim((string) $typed);
        if ($t === '') {
            return null;
        }
        foreach ([self::GROUPS, self::OLDER_GROUPS] as $list) {
            foreach ($list as $key => $label) {
                if (strcasecmp($t, $key) === 0 || strcasecmp($t, $label) === 0) {
                    return $key;
                }
            }
        }

        return mb_substr($t, 0, 60);
    }

    public static function isOpen(?string $status): bool
    {
        $s = trim((string) $status);

        return $s === '' || strcasecmp($s, 'Active') === 0;
    }

    /**
     * The account's events with their figures: open ones by their day (the next first, then those with no date,
     * then those passed), closed ones newest first.
     *
     * @param  'open'|'closed'|'all'  $show
     * @return list<array<string, mixed>>
     */
    public function events(Company $company, string $show = 'open', ?Carbon $now = null): array
    {
        $rows = $this->query((int) $company->id)
            ->when($show === 'open', fn ($q) => $q->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '')->orWhere('p.status', 'Active')))
            ->when($show === 'closed', fn ($q) => $q->whereNotNull('p.status')->where('p.status', '!=', '')->where('p.status', '!=', 'Active'))
            ->get();
        $today = $this->today($company, $now);
        $events = $rows->map(fn ($p) => $this->present($p, $today));

        return $events->sortBy(fn ($e) => match (true) {
            ! $e['open'] => [3, -$e['id'], 0],
            $e['days_left'] === null => [1, -$e['id'], 0],
            $e['days_left'] >= 0 => [0, $e['days_left'], -$e['id']],
            default => [2, -$e['days_left'], -$e['id']],
        })->values()->all();
    }

    /** One event with its figures, or null when it is not this account's. @return array<string, mixed>|null */
    public function event(Company $company, int $programId, ?Carbon $now = null): ?array
    {
        $row = $this->query((int) $company->id)->where('p.id', $programId)->first();

        return $row === null ? null : $this->present($row, $this->today($company, $now));
    }

    /**
     * The pledges of an event, filtered: search (name or phone), status (STATUSES), group
     * (a stored key, '' = every group, '-' = no group), no phone only, sorted by balance, name or newest.
     *
     * @param  array{search?: string, status?: string, group?: string, nophone?: bool, sort?: string}  $f
     */
    public function pledges(int $companyId, int $programId, array $f = []): Builder
    {
        $q = DB::table('contribution_records as r')->where('r.company_id', $companyId)->where('r.budget_program_id', $programId)
            ->where(fn ($w) => $w->where('r.is_deleted', 0)->orWhereNull('r.is_deleted'));
        $search = trim((string) ($f['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
            $digits = preg_replace('/\D+/', '', $search);
            $q->where(fn ($w) => $w->where('r.name', 'like', $like)
                ->when(strlen((string) $digits) >= 3, fn ($x) => $x->orWhere('r.phone', 'like', '%'.ltrim((string) $digits, '0').'%')));
        }
        match ($f['status'] ?? 'all') {
            'unpaid' => $q->where('r.amount', '>', 0)->where('r.paid_amount', '<=', 0),
            'part' => $q->where('r.paid_amount', '>', 0)->where('r.not_paid_amount', '>', 0),
            'paid' => $q->where('r.fully_paid', 'Yes'),
            'owing' => $q->where('r.not_paid_amount', '>', 0),
            default => null,
        };
        $group = (string) ($f['group'] ?? '');
        if ($group === '-') {
            $q->where(fn ($w) => $w->whereNull('r.category_id')->orWhere('r.category_id', ''));
        } elseif ($group !== '') {
            $q->where('r.category_id', $group);
        }
        if (! empty($f['nophone'])) {
            $q->where(fn ($w) => $w->whereNull('r.phone')->orWhere('r.phone', ''));
        }
        match ($f['sort'] ?? 'balance') {
            'name' => $q->orderBy('r.name'),
            'newest' => $q->orderByDesc('r.id'),
            default => $q->orderByDesc('r.not_paid_amount')->orderBy('r.name'),
        };

        return $q;
    }

    /** Count and exact sums of a pledge query. @return array{count: int, pledged: string, paid: string, owed: string} */
    public function totals(Builder $pledges): array
    {
        $t = (clone $pledges)->reorder()->selectRaw('COUNT(*) AS n, COALESCE(SUM(r.amount), 0) AS a, COALESCE(SUM(r.paid_amount), 0) AS p, COALESCE(SUM(r.not_paid_amount), 0) AS o')->first();

        return ['count' => (int) ($t->n ?? 0), 'pledged' => Decimal::of((string) ($t->a ?? 0)), 'paid' => Decimal::of((string) ($t->p ?? 0)), 'owed' => Decimal::of((string) ($t->o ?? 0))];
    }

    /**
     * The groups of an event's pledges, with how many pledges each: the event's own list (EventSettings, or the
     * default list when it has none, as the phone offers them) first, then any other group a pledge uses.
     * Keys are what pledges store (groupKey: "Friends" is the older key "Friend"). @return array<string, array{label: string, count: int}>
     */
    public function groups(int $companyId, int $programId): array
    {
        $used = DB::table('contribution_records')->where('company_id', $companyId)->where('budget_program_id', $programId)->where('is_deleted', 0)
            ->whereNotNull('category_id')->where('category_id', '!=', '')->groupBy('category_id')->selectRaw('category_id AS k, COUNT(*) AS n')->pluck('n', 'k');
        $out = [];
        foreach (EventSettings::groupsOf($companyId, $programId) as $label) {
            $key = (string) self::groupKey($label);
            $out[$key] ??= ['label' => $label, 'count' => (int) ($used[$key] ?? 0)];
        }
        foreach ($used as $key => $n) {
            $out[(string) $key] ??= ['label' => self::groupLabel((string) $key), 'count' => (int) $n];
        }

        return $out;
    }

    /** The latest payments and reversals of an event, newest first. */
    public function recentPayments(int $companyId, int $programId, int $limit = 8): Collection
    {
        return DB::table('contribution_payments as cp')->join('contribution_records as r', 'r.id', '=', 'cp.contribution_record_id')
            ->leftJoin('admin_users as u', 'u.id', '=', 'cp.received_by_id')
            ->where('cp.company_id', $companyId)->where('cp.budget_program_id', $programId)->where('cp.is_deleted', 0)
            ->orderByDesc('cp.id')->limit($limit)
            ->get(['cp.id', 'cp.amount', 'cp.paid_on', 'cp.method', 'cp.source', 'cp.reason', 'cp.receipt_no', 'cp.created_at', 'r.id as record_id', 'r.name', 'u.name as received_by']);
    }

    /**
     * "Worth a look" for one event: short notes that each lead to the pledges (or budget) behind them.
     * Each: key, level (danger | warn | info | ok), text, and a link target: `pledges` filters or `budget`.
     *
     * @param  array<string, mixed>  $event  from event()
     * @return list<array{key: string, level: string, text: string, action: string, to: string, filters: array<string, mixed>}>
     */
    public function insights(Company $company, array $event, callable $money): array
    {
        $cid = (int) $company->id;
        $pid = (int) $event['id'];
        $base = fn () => $this->pledges($cid, $pid);
        $notes = [];

        $unpaid = $this->totals($base()->where('r.amount', '>', 0)->where('r.paid_amount', '<=', 0));
        if ($unpaid['count'] > 0) {
            $notes[] = ['key' => 'unpaid', 'level' => 'warn', 'text' => sprintf('%d %s paid nothing yet: %s pledged.', $unpaid['count'], $unpaid['count'] === 1 ? 'pledger has' : 'pledgers have', $money($unpaid['pledged'])),
                'action' => 'See them', 'to' => 'pledges', 'filters' => ['status' => 'unpaid']];
        }
        $part = $this->totals($base()->where('r.paid_amount', '>', 0)->where('r.not_paid_amount', '>', 0));
        if ($part['count'] > 0) {
            $notes[] = ['key' => 'part', 'level' => 'info', 'text' => sprintf('%d part-paid %s with %s still to come.', $part['count'], $part['count'] === 1 ? 'pledge' : 'pledges', $money($part['owed'])),
                'action' => 'See them', 'to' => 'pledges', 'filters' => ['status' => 'part']];
        }

        $owed = $event['owed'];
        if ($event['days_left'] !== null && Decimal::cmp($owed, '0') > 0) {
            $days = (int) $event['days_left'];
            if ($days < 0) {
                $notes[] = ['key' => 'pace', 'level' => 'danger', 'text' => sprintf('The day has passed and %s of the pledges is still outstanding.', $money($owed)),
                    'action' => 'Remind them', 'to' => 'pledges', 'filters' => ['status' => 'owing']];
            } else {
                $perDay = Decimal::round(bcdiv($owed, (string) max(1, $days), 4));
                $level = ($days <= 14 && $event['collected_pct'] < 75) || ($days <= 30 && $event['collected_pct'] < 50) ? 'warn' : 'info';
                $notes[] = ['key' => 'pace', 'level' => $level,
                    'text' => sprintf('%s and %d%% of what was pledged is in. The rest is about %s a day.', $days === 0 ? 'The day is today' : $days.' '.($days === 1 ? 'day' : 'days').' to go', $event['collected_pct'], $money($perDay)),
                    'action' => 'Remind them', 'to' => 'pledges', 'filters' => ['status' => 'owing']];
            }
        }

        $over = DB::table('budget_items')->where('company_id', $cid)->where('budget_program_id', $pid)->where('is_deleted', 0)
            ->whereColumn('invested_amount', '>', 'target_amount')->orderByRaw('invested_amount - target_amount DESC')->get(['name', 'target_amount', 'invested_amount']);
        if ($over->isNotEmpty()) {
            $extra = $over->reduce(fn ($c, $i) => Decimal::add($c, Decimal::sub((string) $i->invested_amount, (string) $i->target_amount)), '0');
            $names = $over->take(3)->pluck('name')->map(fn ($n) => trim((string) $n))->implode(', ');
            $notes[] = ['key' => 'over', 'level' => 'warn', 'text' => sprintf('%d budget %s over budget by %s (%s%s).', $over->count(), $over->count() === 1 ? 'item is' : 'items are', $money($extra), $names, $over->count() > 3 ? '…' : ''),
                'action' => 'Open the budget', 'to' => 'budget', 'filters' => []];
        }

        $nophone = $base()->where(fn ($w) => $w->whereNull('r.phone')->orWhere('r.phone', ''))->count();
        if ($nophone > 0) {
            $notes[] = ['key' => 'nophone', 'level' => 'info', 'text' => sprintf('%d %s no phone number, so no reminder can reach them.', $nophone, $nophone === 1 ? 'pledge has' : 'pledges have'),
                'action' => 'Add numbers', 'to' => 'pledges', 'filters' => ['nophone' => true]];
        }

        if (Decimal::cmp($event['budget'], '0') > 0 && Decimal::cmp($event['not_pledged'], '0') > 0) {
            $notes[] = ['key' => 'gap', 'level' => 'info', 'text' => sprintf('%s of the budget has no pledge yet.', $money($event['not_pledged'])),
                'action' => 'Add pledges', 'to' => 'pledges', 'filters' => []];
        }

        return $notes;
    }

    // ------------------------------------------------------------------

    private function today(Company $company, ?Carbon $now): Carbon
    {
        return ($now ?? now())->copy()->setTimezone(LocalTime::timezone($company))->startOfDay();
    }

    /** Each event with its budget items and pledges summed alongside (correlated subqueries: one query). */
    private function query(int $companyId): Builder
    {
        $alive = '(%1$s.is_deleted = 0 OR %1$s.is_deleted IS NULL)';
        $sum = fn (string $table, string $expr, string $alias) => DB::raw(sprintf('(SELECT COALESCE(%s, 0) FROM %s x WHERE x.company_id = p.company_id AND x.budget_program_id = p.id AND %s) AS %s',
            $expr, $table, sprintf($alive, 'x'), $alias));

        return DB::table('budget_programs as p')->where('p.company_id', $companyId)->whereRaw(sprintf($alive, 'p'))
            ->select(['p.id', 'p.name', 'p.title', 'p.status', 'p.deadline', 'p.bottom', 'p.rsvp', 'p.created_at',
                $sum('budget_items', 'SUM(x.target_amount)', 'budget'), $sum('budget_items', 'SUM(x.invested_amount)', 'spent'),
                $sum('contribution_records', 'COUNT(*)', 'n'), $sum('contribution_records', 'SUM(x.amount)', 'pledged'),
                $sum('contribution_records', 'SUM(x.paid_amount)', 'paid'), $sum('contribution_records', 'SUM(x.not_paid_amount)', 'owed')]);
    }

    /** @return array<string, mixed> */
    private function present(object $p, Carbon $today): array
    {
        $budget = Decimal::of((string) $p->budget);
        $paid = Decimal::of((string) $p->paid);
        $pledged = Decimal::of((string) $p->pledged);
        $date = $p->deadline ? Carbon::parse($p->deadline, $today->getTimezone())->startOfDay() : null;
        $daysLeft = $date ? (int) round($today->diffInDays($date, false)) : null;
        $needed = Decimal::max(Decimal::sub($budget, $paid), '0');
        $weeks = $daysLeft !== null && $daysLeft >= 0 ? max(1, (int) ceil($daysLeft / 7)) : null;
        $pct = fn (string $part, string $whole) => Decimal::isZero($whole) ? 0 : (int) min(100, (int) floor((float) Decimal::percent($part, $whole))); // display only

        return [
            'id' => (int) $p->id, 'name' => trim((string) ($p->name ?: $p->title)) ?: 'Event', 'title' => trim((string) $p->title),
            'status' => (string) ($p->status ?? ''), 'open' => self::isOpen($p->status), 'bottom' => (string) ($p->bottom ?? ''), 'rsvp' => (string) ($p->rsvp ?? ''),
            'date' => $date?->toDateString(), 'days_left' => $daysLeft,
            'budget' => $budget, 'spent' => Decimal::of((string) $p->spent),
            'pledged' => $pledged, 'paid' => $paid, 'owed' => Decimal::of((string) $p->owed), 'pledges' => (int) $p->n,
            'collected_pct' => $pct($paid, $pledged), 'budget_pct' => $pct($paid, $budget),
            'still_needed' => $needed, 'not_pledged' => Decimal::max(Decimal::sub($budget, $pledged), '0'),
            'weeks_left' => $weeks, 'per_week' => $weeks !== null && Decimal::cmp($needed, '0') > 0 ? Decimal::round(bcdiv($needed, (string) $weeks, 4)) : null,
        ];
    }
}

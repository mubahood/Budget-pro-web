<?php

namespace App\Services\Budget;

use App\Models\Company;
use App\Models\User;
use App\Services\Team\Permissions;
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
 *   pledge's payments (ContributionPayments); still owed = SUM(not_paid_amount), each pledge's max(amount - paid, 0);
 *   extra = SUM of each pledge's max(paid - amount, 0). On every event: Pledged - Collected + Extra = Still owed.
 * - One set of words everywhere (the review's PB-14, decision D7, WORDS): In hand, Collected, Still owed, Paid out,
 *   Budget, Still to find. In hand = Collected - Paid out, and may be negative. Still to find = Budget - Pledged, above 0,
 *   less any budget line someone ticked as covered in kind.
 * - Collected % = collected ÷ pledged; budget % = collected ÷ budget (whole percents, rounded down, at most 100, for
 *   the bars; the figures themselves are never capped).
 * - A pledge is "not paid" with nothing paid, "part paid" with something paid but a balance left, and "paid"
 *   when fully_paid = Yes.
 * - An in-kind pledge (KIND_IN_KIND) has amount 0, so no money figure can include it; it is left out of the pledge list
 *   and counts, and has its own list (inKind).
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

    /** The figures' words, in the order the head of an event shows them. Every screen, document and answer uses these. */
    public const WORDS = ['in_hand' => 'In hand', 'collected' => 'Collected', 'still_owed' => 'Still owed', 'paid_out' => 'Paid out', 'budget' => 'Budget', 'still_to_find' => 'Still to find'];

    public const KIND_IN_KIND = 'in_kind';

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
     * In hand (the review's PB-14): what was collected less what was paid out, from event()'s own figures. May be negative. Reads only.
     *
     * @param  array<string, mixed>  $event  from event()
     */
    public static function inHand(array $event): string
    {
        return Decimal::sub((string) $event['paid'], (string) $event['spent']);
    }

    /**
     * The account's events with their figures: open ones by their day (the next first, then those with no date,
     * then those passed), closed ones newest first.
     *
     * With $for, only the events that person may open (BudgetAccess: a seat, or full access). Whoever shows a person a
     * list of events passes them; without it this is the account's whole list, for documents and tools.
     *
     * @param  'open'|'closed'|'all'  $show
     * @return list<array<string, mixed>>
     */
    public function events(Company $company, string $show = 'open', ?Carbon $now = null, ?User $for = null): array
    {
        $rows = $this->query((int) $company->id)
            ->when($for !== null, fn ($q) => BudgetAccess::limitToSeats($q, $for, 'p.id'))
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
     * The pledges of an event, filtered: search (name or phone; phones: false leaves phone numbers out of it, for
     * someone who may not see them), status (STATUSES), group (a stored key, '' = every group, '-' = no group), no phone
     * only, not reminded in so many days (not_reminded_days), sorted by balance, name or newest. In-kind pledges have their own list.
     *
     * @param  array{search?: string, status?: string, group?: string, nophone?: bool, sort?: string, phones?: bool, not_reminded_days?: int}  $f
     */
    public function pledges(int $companyId, int $programId, array $f = []): Builder
    {
        $q = DB::table('contribution_records as r')->where('r.company_id', $companyId)->where('r.budget_program_id', $programId)
            ->where(fn ($w) => $w->where('r.is_deleted', 0)->orWhereNull('r.is_deleted'))
            ->where(fn ($w) => $w->whereNull('r.kind')->orWhere('r.kind', '!=', self::KIND_IN_KIND));
        $search = trim((string) ($f['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
            $digits = preg_replace('/\D+/', '', $search);
            $q->where(fn ($w) => $w->where('r.name', 'like', $like)
                ->when(($f['phones'] ?? true) && strlen((string) $digits) >= 3, fn ($x) => $x->orWhere('r.phone', 'like', '%'.ltrim((string) $digits, '0').'%')));
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
        if (! empty($f['not_reminded_days'])) {
            $q->whereNotExists(fn ($x) => $x->select(DB::raw(1))->from('pledge_follow_ups as fu')->whereColumn('fu.contribution_record_id', 'r.id')->where('fu.is_deleted', 0)
                ->whereIn('fu.kind', ['reminder', 'call'])->where('fu.created_at', '>=', now()->subDays((int) $f['not_reminded_days'])));
        }
        match ($f['sort'] ?? 'balance') {
            'name' => $q->orderBy('r.name'),
            'newest' => $q->orderByDesc('r.id'),
            default => $q->orderByDesc('r.not_paid_amount')->orderBy('r.name'),
        };

        return $q;
    }

    /** Count and exact sums of a pledge query. @return array{count: int, pledged: string, paid: string, owed: string, extra: string} */
    public function totals(Builder $pledges): array
    {
        $t = (clone $pledges)->reorder()->selectRaw('COUNT(*) AS n, COALESCE(SUM(r.amount), 0) AS a, COALESCE(SUM(r.paid_amount), 0) AS p, COALESCE(SUM(r.not_paid_amount), 0) AS o, COALESCE(SUM(GREATEST(r.paid_amount - r.amount, 0)), 0) AS x')->first();

        return ['count' => (int) ($t->n ?? 0), 'pledged' => Decimal::of((string) ($t->a ?? 0)), 'paid' => Decimal::of((string) ($t->p ?? 0)), 'owed' => Decimal::of((string) ($t->o ?? 0)), 'extra' => Decimal::of((string) ($t->x ?? 0))];
    }

    /** The withdrawn pledges of an event, newest first, each with who, when and why (PB-04). */
    public function withdrawn(int $companyId, int $programId): Collection
    {
        return DB::table('contribution_records as r')->leftJoin('admin_users as u', 'u.id', '=', 'r.withdrawn_by_id')
            ->where('r.company_id', $companyId)->where('r.budget_program_id', $programId)->where('r.is_deleted', 1)->whereNotNull('r.withdrawn_at')
            ->orderByDesc('r.withdrawn_at')->orderByDesc('r.id')
            ->get(['r.id', 'r.name', 'r.phone', 'r.amount', 'r.category_id', 'r.treasurer_id', 'r.budget_program_id', 'r.created_at', 'r.withdrawn_at', 'r.withdrawn_reason', 'u.name as withdrawn_by']);
    }

    public function withdrawnCount(int $companyId, int $programId): int
    {
        return DB::table('contribution_records')->where('company_id', $companyId)->where('budget_program_id', $programId)->where('is_deleted', 1)->whereNotNull('withdrawn_at')->count();
    }

    /** The in-kind pledges of an event: what it is and its value, never in the money figures (PB-39). */
    public function inKind(int $companyId, int $programId): Collection
    {
        return DB::table('contribution_records as r')->where('r.company_id', $companyId)->where('r.budget_program_id', $programId)->where('r.is_deleted', 0)
            ->where('r.kind', self::KIND_IN_KIND)->orderBy('r.name')->get(['r.id', 'r.name', 'r.phone', 'r.category_id', 'r.in_kind_what', 'r.in_kind_value']);
    }

    /**
     * Who can be named as having taken money or a pledge on this event, for a searchable list: a teammate with a seat
     * or with full access (BudgetAccess::onTeam), by name. Read only.
     *
     * @return list<array{id: int, name: string, role: ?string}>
     */
    public function team(int $companyId, int $programId): array
    {
        $seats = DB::table('budget_program_members')->where('company_id', $companyId)->where('budget_program_id', $programId)->where('is_deleted', 0)->where('status', 'active')
            ->whereNotNull('user_id')->pluck('role', 'user_id');
        $out = [];
        foreach (User::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('name')->get() as $user) {
            $seat = $seats[$user->id] ?? null;
            if (Permissions::roleOf($user) !== 'none' && ($seat !== null || BudgetAccess::fullAccess($user))) {
                $out[] = ['id' => (int) $user->id, 'name' => trim((string) $user->name) ?: 'Team member', 'role' => $seat === null ? null : (string) $seat];
            }
        }

        return $out;
    }

    /** team() as a list of choices. @return array<int, string> id => name */
    public function receivers(int $companyId, int $programId): array
    {
        return array_column($this->team($companyId, $programId), 'name', 'id');
    }

    /**
     * An event's money in one place, for the phones and the reports, read only: the figures in WORDS with Pledged and
     * Extra, how the money came, and who is holding it. by_method and holders each add up to Collected.
     *
     * @return array<string, mixed>
     */
    public function position(int $companyId, int $programId): array
    {
        $row = $this->query($companyId)->where('p.id', $programId)->first();
        if ($row === null) {
            return [];
        }
        $e = $this->present($row, now()->startOfDay());

        return ['words' => self::WORDS, 'in_hand' => $e['in_hand'], 'collected' => $e['collected'], 'still_owed' => $e['still_owed'], 'paid_out' => $e['paid_out'], 'budget' => $e['budget'],
            'still_to_find' => $e['still_to_find'], 'pledged' => $e['pledged'], 'extra' => $e['extra'], 'by_method' => $this->byMethod($companyId, $programId), 'holders' => $this->holders($companyId, $programId),
            'paid_out_by_method' => BudgetPayments::ready() ? (new BudgetPayments())->byMethod($companyId, $programId) : []];
    }

    /** What came in by each way of paying, reversals taken off, biggest first. @return list<array{method: string, amount: string}> */
    public function byMethod(int $companyId, int $programId): array
    {
        return DB::table('contribution_payments as cp')->join('contribution_records as r', 'r.id', '=', 'cp.contribution_record_id')
            ->where('cp.company_id', $companyId)->where('cp.budget_program_id', $programId)->where('cp.is_deleted', 0)->where('r.is_deleted', 0)
            ->groupBy('cp.method')->selectRaw('cp.method AS m, SUM(cp.amount) AS a')->orderByDesc('a')->get()
            ->filter(fn ($row) => Decimal::cmp((string) $row->a, '0') !== 0)
            ->map(fn ($row) => ['method' => (string) $row->m, 'amount' => Decimal::of((string) $row->a)])->values()->all();
    }

    /**
     * Who is holding the money (OB-06): what each person took, less what was reversed, biggest first. A reversal comes off
     * the person who took the payment, including a reversal from before this existed, which named who reversed it. Money
     * nobody is named on (from before the history) is id 0, "Not recorded".
     *
     * @return list<array{id: int, name: string, amount: string}>
     */
    public function holders(int $companyId, int $programId): array
    {
        return DB::table('contribution_payments as cp')->join('contribution_records as r', 'r.id', '=', 'cp.contribution_record_id')
            ->leftJoin('contribution_payments as o', 'o.id', '=', 'cp.reverses_id')
            ->leftJoin('admin_users as u', 'u.id', '=', DB::raw('COALESCE(o.received_by_id, cp.received_by_id)'))
            ->where('cp.company_id', $companyId)->where('cp.budget_program_id', $programId)->where('cp.is_deleted', 0)->where('r.is_deleted', 0)
            ->groupBy('u.id', 'u.name')->selectRaw('u.id AS id, u.name AS n, SUM(cp.amount) AS a')->orderByDesc('a')->get()
            ->filter(fn ($row) => Decimal::cmp((string) $row->a, '0') !== 0)
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->id ? (trim((string) $row->n) ?: 'Team member') : 'Not recorded', 'amount' => Decimal::of((string) $row->a)])->values()->all();
    }

    /** Every payment and reversal of an event, oldest first, naming who took it and who recorded it, for reports and exports. */
    public function payments(int $companyId, int $programId): Collection
    {
        return DB::table('contribution_payments as cp')->join('contribution_records as r', 'r.id', '=', 'cp.contribution_record_id')
            ->leftJoin('admin_users as u', 'u.id', '=', 'cp.received_by_id')->leftJoin('admin_users as c', 'c.id', '=', 'cp.created_by_id')
            ->where('cp.company_id', $companyId)->where('cp.budget_program_id', $programId)->where('cp.is_deleted', 0)->where('r.is_deleted', 0)
            ->orderBy('cp.paid_on')->orderBy('cp.id')
            ->get(['cp.id', 'cp.amount', 'cp.paid_on', 'cp.method', 'cp.reference', 'cp.receipt_no', 'cp.source', 'cp.reason', 'cp.reverses_id', 'r.id as record_id', 'r.name', 'u.name as received_by', 'c.name as recorded_by']);
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

    /** The latest payments and reversals of an event, by the day they were paid, then newest first (PB-20). */
    public function recentPayments(int $companyId, int $programId, int $limit = 8): Collection
    {
        return DB::table('contribution_payments as cp')->join('contribution_records as r', 'r.id', '=', 'cp.contribution_record_id')
            ->leftJoin('admin_users as u', 'u.id', '=', 'cp.received_by_id')->leftJoin('admin_users as c', 'c.id', '=', 'cp.created_by_id')
            ->where('cp.company_id', $companyId)->where('cp.budget_program_id', $programId)->where('cp.is_deleted', 0)->where('r.is_deleted', 0)
            ->orderByDesc('cp.paid_on')->orderByDesc('cp.id')->limit($limit)
            ->get(['cp.id', 'cp.amount', 'cp.paid_on', 'cp.method', 'cp.source', 'cp.reason', 'cp.receipt_no', 'cp.created_at', 'r.id as record_id', 'r.name', 'u.name as received_by', 'c.name as recorded_by']);
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
                $notes[] = ['key' => 'pace', 'level' => 'danger', 'text' => sprintf('The day has passed and %s of the pledges is still owed.', $money($owed)),
                    'action' => 'Remind them', 'to' => 'pledges', 'filters' => ['status' => 'owing']];
            } else {
                $level = ($days <= 14 && $event['collected_pct'] < 75) || ($days <= 30 && $event['collected_pct'] < 50) ? 'warn' : 'info';
                $notes[] = ['key' => 'pace', 'level' => $level,
                    'text' => sprintf('%s and %d%% of what was pledged is in.', $days === 0 ? 'The day is today' : $days.' '.($days === 1 ? 'day' : 'days').' to go', $event['collected_pct']),
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

        if (Decimal::cmp((string) $event['in_hand'], '0') < 0) {
            $notes[] = ['key' => 'over_paid_out', 'level' => 'danger', 'text' => sprintf('Paid out %s more than was collected: nothing is left in hand.', $money(Decimal::neg((string) $event['in_hand']))),
                'action' => 'Open the budget', 'to' => 'budget', 'filters' => []];
        }

        $nophone = $base()->where(fn ($w) => $w->whereNull('r.phone')->orWhere('r.phone', ''))->count();
        if ($nophone > 0) {
            $notes[] = ['key' => 'nophone', 'level' => 'info', 'text' => sprintf('%d %s no phone number, so no reminder can reach them.', $nophone, $nophone === 1 ? 'pledge has' : 'pledges have'),
                'action' => 'Add numbers', 'to' => 'pledges', 'filters' => ['nophone' => true]];
        }

        if (Decimal::cmp($event['budget'], '0') > 0 && Decimal::cmp($event['not_pledged'], '0') > 0) {
            $notes[] = ['key' => 'gap', 'level' => 'info', 'text' => sprintf('Still to find: %s of the budget has no pledge yet.', $money($event['not_pledged'])),
                'action' => 'Add pledges', 'to' => 'pledges', 'filters' => []];
        }

        if (($n = $this->sameNames($cid, $pid)) > 0) {
            $notes[] = ['key' => 'same_name', 'level' => 'info', 'text' => sprintf('%d pledges share a name. Same person?', $n),
                'action' => 'See them', 'to' => 'pledges', 'filters' => ['sort' => 'name']];
        }

        if (($n = $this->doubleReferences($pid)) > 0) {
            $notes[] = ['key' => 'double_payment', 'level' => 'warn', 'text' => sprintf('Possible double payment: %d %s used more than once.', $n, $n === 1 ? 'reference was' : 'references were'),
                'action' => 'See them', 'to' => 'pledges', 'filters' => []];
        }

        $today = $this->today($company, null)->toDateString();
        if (($n = (new PledgeFollowUps())->promisedAndUnpaid($cid, $pid, $today)) > 0) {
            $notes[] = ['key' => 'promised', 'level' => 'warn', 'text' => sprintf('%d said they would pay by now and %s not.', $n, $n === 1 ? 'has' : 'have'),
                'action' => 'See them', 'to' => 'pledges', 'filters' => ['status' => 'owing']];
        }

        return $notes;
    }

    // ------------------------------------------------------------------

    /** How many live pledges in an event share a name with another (any case, however spaced). */
    private function sameNames(int $companyId, int $programId): int
    {
        $names = DB::table('contribution_records')->where('company_id', $companyId)->where('budget_program_id', $programId)->where('is_deleted', 0)->pluck('name');
        $n = 0;
        foreach ($names->map(fn ($name) => PledgeService::nameKey((string) $name))->countBy() as $count) {
            $n += $count > 1 ? $count : 0;
        }

        return $n;
    }

    /** How many references stand on more than one payment that was not reversed. */
    private function doubleReferences(int $programId): int
    {
        $twice = DB::table('contribution_payments')->where('budget_program_id', $programId)->where('is_deleted', 0)->whereNull('reverses_id')->whereNotNull('reference')->where('reference', '!=', '')
            ->whereNotIn('id', DB::table('contribution_payments')->where('budget_program_id', $programId)->whereNotNull('reverses_id')->select('reverses_id'))
            ->groupByRaw('LOWER(reference)')->havingRaw('COUNT(*) > 1')->selectRaw('LOWER(reference) AS ref');

        return DB::query()->fromSub($twice, 'refs')->count();
    }

    private function today(Company $company, ?Carbon $now): Carbon
    {
        return ($now ?? now())->copy()->setTimezone(LocalTime::timezone($company))->startOfDay();
    }

    /** Each event with its budget items and pledges summed alongside (correlated subqueries: one query). */
    private function query(int $companyId): Builder
    {
        $alive = '(%1$s.is_deleted = 0 OR %1$s.is_deleted IS NULL)';
        $sum = fn (string $table, string $expr, string $alias, string $also = '') => DB::raw(sprintf('(SELECT COALESCE(%s, 0) FROM %s x WHERE x.company_id = p.company_id AND x.budget_program_id = p.id AND %s%s) AS %s',
            $expr, $table, sprintf($alive, 'x'), $also, $alias));
        $money = sprintf(" AND (x.kind IS NULL OR x.kind != '%s')", self::KIND_IN_KIND);

        return DB::table('budget_programs as p')->where('p.company_id', $companyId)->whereRaw(sprintf($alive, 'p'))
            ->select(['p.id', 'p.name', 'p.title', 'p.status', 'p.deadline', 'p.closed_at', 'p.bottom', 'p.rsvp', 'p.created_at',
                $sum('budget_items', 'SUM(x.target_amount)', 'budget'), $sum('budget_items', 'SUM(x.invested_amount)', 'spent'),
                $sum('budget_items', 'SUM(CASE WHEN x.covered_by_record_id IS NOT NULL THEN x.target_amount ELSE 0 END)', 'covered'),
                $sum('contribution_records', 'COUNT(*)', 'n', $money), $sum('contribution_records', 'SUM(x.amount)', 'pledged', $money),
                $sum('contribution_records', 'SUM(x.paid_amount)', 'paid', $money), $sum('contribution_records', 'SUM(x.not_paid_amount)', 'owed', $money),
                $sum('contribution_records', 'SUM(GREATEST(x.paid_amount - x.amount, 0))', 'extra', $money)]);
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
        $spent = Decimal::of((string) $p->spent);
        $owed = Decimal::of((string) $p->owed);
        $toFind = Decimal::max(Decimal::sub(Decimal::sub($budget, (string) $p->covered), $pledged), '0');

        return [
            // The six words (WORDS), and Extra. The keys below them are the same figures under their older names.
            'in_hand' => self::inHand(['paid' => $paid, 'spent' => $spent]), 'collected' => $paid, 'still_owed' => $owed, 'paid_out' => $spent, 'still_to_find' => $toFind,
            'extra' => Decimal::of((string) $p->extra),

            'id' => (int) $p->id, 'name' => trim((string) ($p->name ?: $p->title)) ?: 'Event', 'title' => trim((string) $p->title),
            'status' => (string) ($p->status ?? ''), 'open' => self::isOpen($p->status), 'closed_at' => $p->closed_at, 'bottom' => (string) ($p->bottom ?? ''), 'rsvp' => (string) ($p->rsvp ?? ''),
            'date' => $date?->toDateString(), 'days_left' => $daysLeft,
            'budget' => $budget, 'spent' => $spent,
            'pledged' => $pledged, 'paid' => $paid, 'owed' => $owed, 'pledges' => (int) $p->n,
            'collected_pct' => $pct($paid, $pledged), 'budget_pct' => $pct($paid, $budget),
            'still_needed' => $needed, 'not_pledged' => $toFind,
            'weeks_left' => $weeks, 'per_week' => $weeks !== null && Decimal::cmp($needed, '0') > 0 ? Decimal::round(bcdiv($needed, (string) $weeks, 4)) : null,
        ];
    }
}

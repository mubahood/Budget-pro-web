<?php

namespace App\Services\Rentals\Today;

use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\User;
use App\Services\Rentals\Collections\Arrears;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Portal\TenantRequests;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\MoneyPosition;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Today's work for the phone (`GET rentals/today`): the web's Today, as data, with a `link` where the web has an address. It is the kernel's copy of the portal's
 * App\Shop\RentalRules (Chase, TakeIn, Renew, Fix, Fill, MonthEnd), with the same words and the same rules, because the API can't call the portal. A change to one is a
 * change to the other, until the portal is moved onto this one. A group shows only to someone who can act on it, and everything is narrowed to the person's properties.
 */
final class TodayWork
{
    /** Lines a group shows. */
    public const EACH = 5;

    private readonly int $cid;

    private readonly Carbon $today;

    private readonly int $grace;

    public function __construct(private readonly User $by)
    {
        $this->cid = (int) $by->company_id;
        $this->today = LocalDate::today($this->cid);
        $this->grace = (int) RentalsModule::settings($this->cid)->grace_days;
    }

    /** The next step for an overdue tenant, by how long they have been overdue (the portal's ChaseStep). */
    public static function nextStep(int $days): string
    {
        return match (true) {
            $days <= 14 => 'remind',
            $days <= 30 => 'call',
            $days <= 60 => 'visit',
            default => 'final',
        };
    }

    /**
     * The groups, in the web's order. A tenant has one line on the page: a second reason joins the first line's words.
     *
     * @return list<array{key: string, title: string, total: int, lines: list<array<string, mixed>>}>
     */
    public function groups(int $each = self::EACH): array
    {
        $out = [];
        $seen = [];
        $rules = [['chase', 'Chase', 'rentals_collect', fn () => $this->chase()], ['take_in', 'Take in', 'rentals_collect', fn () => $this->takeIn()],
            ['renew', 'Renew', 'rentals_manage', fn () => $this->renew($each)], ['fix', 'Fix', 'rentals_maintenance', fn () => $this->fix($each)], ['fill', 'Fill', 'rentals_manage', fn () => $this->fill()]];
        foreach ($rules as [$key, $title, $permission, $rule]) {
            if (! RentalAccess::can($this->by, $permission)) {
                continue;
            }
            ['total' => $total, 'lines' => $found] = $rule();
            $lines = [];
            foreach (array_slice($found, 0, $each) as $row) {
                $line = (object) $row;
                if ($line->tenant_id !== null && isset($seen[$line->tenant_id])) {
                    $seen[$line->tenant_id]->detail .= ' · '.$line->detail;

                    continue;
                }
                if ($line->tenant_id !== null) {
                    $seen[$line->tenant_id] = $line;
                }
                $lines[] = $line;
            }
            if ($lines !== []) {
                $out[] = ['key' => $key, 'title' => $title, 'total' => $total, 'lines' => $lines];
            }
        }
        $out = array_map(fn (array $g) => ['lines' => array_map(fn (object $l) => (array) $l, $g['lines'])] + $g, $out);
        if (($month = $this->monthEnd()) !== []) {
            $out[] = ['key' => 'month_end', 'title' => 'Month end', 'total' => count($month), 'lines' => $month];
        }

        return $out;
    }

    /**
     * The three steps of a new account, until the first tenant is in. Null once there is a lease, or for someone who can't do them.
     *
     * @return list<array{key: string, label: string, done: bool}>|null
     */
    public function firstSteps(): ?array
    {
        if (! self::isSetUp($this->cid) || ! RentalAccess::can($this->by, 'rentals_manage') || $this->table('rental_leases')->exists()) {
            return null;
        }
        $properties = $this->table('rental_properties', '', 'id')->exists();
        $units = $properties && $this->table('rental_units')->exists();

        return [['key' => 'add_property', 'label' => 'Add a property', 'done' => $properties], ['key' => 'add_units', 'label' => 'Add its units', 'done' => $units], ['key' => 'move_in', 'label' => 'Move a tenant in', 'done' => false]];
    }

    public static function isSetUp(int $companyId): bool
    {
        return RentalsModule::settings($companyId)->setup_at !== null;
    }

    /**
     * Whose money the cash is today: only for someone who reads landlords' money, and never a login limited to some properties, whatever its role was given. Owner mode has no others to hold it for.
     *
     * @return array<string, mixed>|null
     */
    public function moneyPosition(): ?array
    {
        if (! RentalAccess::can($this->by, 'rentals_landlords') || RentalAccess::propertyIds($this->by) !== null || RentalsModule::isOwnerMode($this->cid)) {
            return null;
        }

        return MoneyPosition::asOf($this->cid, $this->today->toDateString());
    }

    /**
     * The figures the web's Today shows, line for line (the portal's RentalsBoard::overview): this month's bills without deposits, what came in, and what is owed to landlords, which is the
     * whole account's, so a login limited to some properties never gets it (EL-26). Figures::overview, in `figures`, counts the month differently, and older screens read that.
     *
     * @return array<string, mixed>
     */
    public function board(): array
    {
        $from = $this->today->copy()->startOfMonth()->toDateString();
        $to = $this->today->copy()->endOfMonth()->toDateString();
        $units = (int) $this->table('rental_units')->whereNotIn('status', ['inactive', 'in_use'])
            ->whereIn('property_id', DB::table('rental_properties')->where('company_id', $this->cid)->where('status', 'active')->select('id'))->count(); // a building given up leaves the count, as on the overview
        $occupied = (int) $this->table('rental_leases')->whereIn('status', RentalLease::LIVE)->distinct()->count('unit_id');
        $bills = $this->table('rental_charges')->where('status', '!=', 'void')->whereNotIn('kind', ['deposit', 'refund'])->whereBetween('due_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(amount_minor), 0) AS billed, COALESCE(SUM(paid_minor), 0) AS paid')->first();
        $billed = (int) ($bills->billed ?? 0);
        $vacant = $this->vacantUnits()['n'];
        $balances = RentalAccess::propertyIds($this->by) === null ? LandlordAccounts::balances($this->cid) : null;
        $withLandlords = $this->table('rental_leases')->where('deposit_held_by', 'landlord')->pluck('id')->map(fn ($v) => (int) $v)->all();

        return ['units' => $units, 'occupied' => min($occupied, $units ?: $occupied), 'vacant' => $vacant, 'held_back' => max(0, $units - $occupied - $vacant),
            'occupancy_pct' => $units > 0 ? (int) round(min($occupied, $units) * 100 / $units) : 0,
            'billed_minor' => $billed, 'billed_paid_minor' => (int) ($bills->paid ?? 0),
            'collected_minor' => (int) $this->table('rental_payments')->whereIn('method', RentalPayment::CASH_METHODS)->whereNull('reversed_at')->whereBetween('paid_on', [$from, $to])->sum('amount_minor'),
            'rate_pct' => $billed > 0 ? (int) floor(((int) $bills->paid) * 100 / $billed) : null,
            'arrears' => Arrears::aging($this->cid, $this->by, $this->today), 'credit_minor' => (int) $this->table('rental_payments')->whereNull('reversed_at')->sum('unallocated_minor'),
            'deposits_minor' => max(0, (int) $this->table('rental_entries')->sum('deposit_minor')), 'deposits_landlords_minor' => $withLandlords === [] ? 0 : (int) array_sum(Leases::depositsHeld($withLandlords)),
            'landlords_owed_minor' => $balances === null ? null : (int) array_sum(array_filter($balances, fn ($b) => $b > 0)),
            'landlords_owe_minor' => $balances === null ? null : (int) -array_sum(array_filter($balances, fn ($b) => $b < 0))];
    }

    /** Units that can be let now: available, on an active property, with no live lease. Asked once so Today's figure and its Fill line agree. @return array{n: int, asking: int} */
    private function vacantUnits(): array
    {
        $row = $this->table('rental_units', 'u')->join('rental_properties as p', 'p.id', '=', 'u.property_id')->where('u.status', 'available')->where('p.status', 'active')
            ->whereNotExists(fn ($q) => $q->from('rental_leases as l')->whereColumn('l.unit_id', 'u.id')->whereIn('l.status', RentalLease::LIVE))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(u.rent_minor), 0) AS asking')->first();

        return ['n' => (int) ($row->n ?? 0), 'asking' => (int) ($row->asking ?? 0)];
    }

    /**
     * What this person took today, by method: the cash they should have on them.
     *
     * @return array{date: string, total_minor: int, count: int, by_method: array<string, int>}
     */
    public function myCash(): array
    {
        $day = $this->today->toDateString();
        $rows = DB::table('rental_payments')->where('company_id', $this->cid)->where('received_by_id', $this->by->id)->where('paid_on', $day)->whereNull('reversed_at')
            ->whereIn('method', RentalPayment::CASH_METHODS)->groupBy('method')->selectRaw('method, SUM(amount_minor) AS total, COUNT(*) AS n')->get()->keyBy('method');
        $by = array_fill_keys(RentalPayment::CASH_METHODS, 0);
        foreach ($by as $method => $_) {
            $by[$method] = (int) ($rows->get($method)->total ?? 0);
        }

        return ['date' => $day, 'total_minor' => array_sum($by), 'count' => (int) $rows->sum('n'), 'by_method' => $by];
    }

    /** The month that closes next, for someone who can close it. */
    public function monthEndNext(): ?string
    {
        return RentalAccess::can($this->by, 'rentals_close') && RentalAccess::propertyIds($this->by) === null ? MonthClose::nextMonth($this->cid) : null;
    }

    // ── The rules ──

    /**
     * Promises due first, then the biggest overdue. One line for each tenant, whatever the reasons.
     *
     * @return array{total: int, lines: list<array<string, mixed>>}
     */
    private function chase(): array
    {
        $today = $this->today->toDateString();
        $rows = Arrears::list($this->cid, $this->by, ['sort' => 'amount'], $this->today);
        $promised = array_filter($rows, fn ($r) => ($r['last_follow_up']['promise_on'] ?? null) !== null && $r['last_follow_up']['promise_on'] <= $today);
        usort($promised, fn ($a, $b) => $a['last_follow_up']['promise_on'] <=> $b['last_follow_up']['promise_on']);
        $seen = [];
        $ordered = [];
        foreach ([...$promised, ...$rows] as $r) {
            if (! isset($seen[$r['tenant']['id']])) {
                $seen[$r['tenant']['id']] = true;
                $ordered[] = $r;
            }
        }

        return ['total' => count($ordered), 'lines' => array_map(function ($r) use ($today) {
            $promise = $r['last_follow_up']['promise_on'] ?? null;
            $days = (int) $r['days'];
            [$kind, $why] = match (true) {
                $promise !== null && $promise === $today => ['promise_today', 'Promised for today'],
                $promise !== null && $promise < $today => ['promise_broken', 'Promised '.Carbon::parse($promise)->format('j M').', still overdue'],
                default => ['overdue', 'Overdue '.self::days($days)],
            };

            return self::line($kind, $r['tenant']['name'].' · '.$r['unit']['code'], $why.' · '.$r['property']['name'], ['type' => 'lease', 'id' => (int) $r['lease_id']],
                ['amount_minor' => (int) $r['overdue_minor'], 'overdue' => true, 'promise_on' => $promise, 'tenant_id' => (int) $r['tenant']['id'], 'next_step' => self::nextStep($days)]);
        }, array_slice($ordered, 0, self::EACH))];
    }

    /**
     * Money and tenants coming in or leaving now: tenants on notice (their own notice still to confirm, and those moving out within 30 days), and bills due today.
     *
     * @return array{total: int, lines: list<array<string, mixed>>}
     */
    private function takeIn(): array
    {
        $lines = [...$this->notices(), ...$this->dueToday()];

        return ['total' => count($lines), 'lines' => $lines];
    }

    /** @return list<array<string, mixed>> */
    private function dueToday(): array
    {
        $rows = $this->table('rental_charges', 'c')->join('rental_leases as l', 'l.id', '=', 'c.lease_id')->join('rental_tenants as t', 't.id', '=', 'l.tenant_id')->join('rental_units as u', 'u.id', '=', 'l.unit_id')
            ->where('c.status', 'open')->where('c.kind', '!=', 'refund')->where('c.due_date', $this->today->toDateString())
            ->groupBy('c.lease_id', 'l.tenant_id', 't.name', 'u.code')->orderByRaw('SUM(c.amount_minor - c.paid_minor) DESC')
            ->get(['c.lease_id', 'l.tenant_id', 't.name as tenant', 'u.code', DB::raw('SUM(c.amount_minor - c.paid_minor) AS left_minor')]);

        return $rows->map(fn ($r) => self::line('due_today', $r->tenant.' · '.$r->code, 'Due today', ['type' => 'lease', 'id' => (int) $r->lease_id],
            ['amount_minor' => (int) $r->left_minor, 'tenant_id' => (int) $r->tenant_id]))->all();
    }

    /** @return list<array<string, mixed>> */
    private function notices(): array
    {
        $soon = $this->today->copy()->addDays(30)->toDateString();
        $lines = [];
        $asked = DB::table('rental_activities')->where('company_id', $this->cid)->where('action', TenantRequests::NOTICE_ACTION)->where('subject_type', 'Lease')->orderByDesc('id')->limit(20)->pluck('subject_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $pending = $asked === [] ? [] : TenantRequests::pendingNotices($this->cid, $asked);
        $leases = $this->table('rental_leases', 'l')->join('rental_tenants as t', 't.id', '=', 'l.tenant_id')->join('rental_units as u', 'u.id', '=', 'l.unit_id');
        if ($pending !== []) {
            foreach ((clone $leases)->whereIn('l.id', array_keys($pending))->where('l.status', 'active')->get(['l.id', 'l.tenant_id', 't.name as tenant', 'u.code']) as $l) {
                $day = $pending[(int) $l->id]['move_out_on'] ?? null;
                $lines[] = self::line('notice_sent', $l->tenant.' · '.$l->code, 'Sent notice: moving out '.($day ? Carbon::parse($day)->format('j M') : '').'. Confirm it to stop the bills', ['type' => 'lease', 'id' => (int) $l->id], ['tenant_id' => (int) $l->tenant_id]);
            }
        }
        foreach ((clone $leases)->where('l.status', 'notice')->whereNotNull('l.move_out_on')->where('l.move_out_on', '<=', $soon)->orderBy('l.move_out_on')
            ->get(['l.id', 'l.tenant_id', 'l.move_out_on', 't.name as tenant', 'u.code', 'u.water_meter', 'u.power_meter']) as $l) {
            $reading = $l->water_meter ? 'final water reading needed' : ($l->power_meter ? 'final power reading needed' : null);
            $lines[] = self::line('moving_out', $l->tenant.' · '.$l->code, 'Move out on '.Carbon::parse($l->move_out_on)->format('j M').($reading ? '; '.$reading : ''), ['type' => 'lease', 'id' => (int) $l->id], ['tenant_id' => (int) $l->tenant_id]);
        }

        return $lines;
    }

    /**
     * Fixed terms that ran out with the tenant still in, then those ending within 30 days.
     *
     * @return array{total: int, lines: list<array<string, mixed>>}
     */
    private function renew(int $each): array
    {
        $today = $this->today->toDateString();
        $soon = $this->today->copy()->addDays(30)->toDateString();
        $q = $this->table('rental_leases', 'l')->join('rental_tenants as t', 't.id', '=', 'l.tenant_id')->join('rental_units as u', 'u.id', '=', 'l.unit_id')
            ->where('l.status', 'active')->whereNotNull('l.end_date')->where('l.end_date', '<=', $soon);
        $rows = (clone $q)->orderBy('l.end_date')->limit($each)->get(['l.id', 'l.tenant_id', 'l.end_date', 'l.rent_minor', 't.name as tenant', 'u.code']);

        return ['total' => (clone $q)->count(), 'lines' => $rows->map(fn ($l) => self::line($l->end_date < $today ? 'term_ended' : 'term_ending', $l->tenant.' · '.$l->code,
            ($l->end_date < $today ? 'Term ended ' : 'Term ends ').Carbon::parse($l->end_date)->format('j M').'. Renew at '.Minor::format((int) $l->rent_minor, $this->cid), ['type' => 'lease', 'id' => (int) $l->id],
            ['tenant_id' => (int) $l->tenant_id]))->all()];
    }

    /**
     * Urgent repairs still open.
     *
     * @return array{total: int, lines: list<array<string, mixed>>}
     */
    private function fix(int $each): array
    {
        $q = $this->table('rental_maintenance', 'm')->join('rental_properties as p', 'p.id', '=', 'm.property_id')->leftJoin('rental_units as u', 'u.id', '=', 'm.unit_id')
            ->whereIn('m.status', ['open', 'in_progress'])->where('m.priority', 'urgent');
        $rows = (clone $q)->orderBy('m.id')->limit($each)->get(['m.id', 'm.number', 'm.title', 'p.name as property', 'u.code']);

        return ['total' => (clone $q)->count(), 'lines' => $rows->map(fn ($m) => self::line('urgent_repair', (string) $m->title, 'Urgent · '.$m->number.' · '.$m->property.($m->code ? ' · '.$m->code : ''), ['type' => 'maintenance', 'id' => (int) $m->id]))->all()];
    }

    /**
     * Vacant units, as one line whatever their number: never a row for each door.
     *
     * @return array{total: int, lines: list<array<string, mixed>>}
     */
    private function fill(): array
    {
        ['n' => $n, 'asking' => $asking] = $this->vacantUnits();
        if ($n === 0) {
            return ['total' => 0, 'lines' => []];
        }

        return ['total' => $n, 'lines' => [self::line('vacant', number_format($n).' vacant, asking '.$this->short($asking).' a month', 'See which ones', ['type' => 'units', 'id' => null, 'params' => ['state' => 'vacant']], ['amount_minor' => $asking])]];
    }

    /**
     * The month's closing work: from the 25th a checklist, computed from the books; in the first ten days the report of the month that just ended. Only for
     * people who can do that work; a caretaker has none. Nothing here while the account is still on its first steps.
     *
     * @return list<array<string, mixed>>
     */
    private function monthEnd(): array
    {
        if (! RentalAccess::can($this->by, 'rentals_reports') || RentalAccess::propertyIds($this->by) !== null) {
            return [];
        }
        if ($this->today->day >= 25) {
            return $this->checklist();
        }

        return $this->lastMonthReport();
    }

    /** @return list<array<string, mixed>> */
    private function checklist(): array
    {
        $today = $this->today;
        $from = $today->copy()->startOfMonth()->toDateString();
        $to = $today->copy()->endOfMonth()->toDateString();
        $cid = $this->cid;
        $cutoff = $today->copy()->subDays($this->grace)->toDateString();
        $items = [];

        $overdue = $this->table('rental_charges')->where('status', 'open')->where('kind', '!=', 'refund')->where('due_date', '<', $cutoff)->distinct()->pluck('lease_id');
        $chased = $overdue->isEmpty() ? 0 : DB::table('rental_follow_ups')->where('company_id', $cid)->whereIn('lease_id', $overdue)->where('created_at', '>=', $from.' 00:00:00')->distinct()->count('lease_id');
        $notChased = $overdue->count() - $chased;
        $items[] = self::line('arrears_chased', 'Arrears chased', $overdue->isEmpty() ? 'Nothing owed that is overdue' : ($notChased === 0 ? 'Everyone overdue was followed up this month' : number_format($notChased).' overdue with no follow-up this month'),
            ['type' => 'arrears', 'id' => null], ['done' => $notChased === 0]);

        $behind = $this->table('rental_leases')->where('status', 'active')->where(fn ($q) => $q->whereNull('billed_until')->orWhere('billed_until', '<', $today->toDateString()))->count();
        $items[] = self::line('bills_raised', 'Bills raised', $behind === 0 ? 'Every lease is billed' : number_format($behind).' '.($behind === 1 ? 'lease has' : 'leases have').' no bill for now', ['type' => 'billing', 'id' => null], ['done' => $behind === 0]);

        if (RentalAccess::can($this->by, 'rentals_expenses')) {
            $expenses = DB::table('rental_expenses')->where('company_id', $cid)->whereNull('reversed_at')->whereBetween('paid_on', [$from, $to])->count();
            $items[] = self::line('expenses_in', 'Expenses in', $expenses === 0 ? 'None recorded this month' : number_format($expenses).' recorded this month', ['type' => 'expenses', 'id' => null], ['done' => $expenses > 0]);
        }

        if (RentalAccess::can($this->by, 'rentals_landlords') && ! RentalsModule::isOwnerMode($cid)) {
            $owed = array_keys(array_filter(LandlordAccounts::balances($cid), fn ($b) => $b > 0));
            $paid = $owed === [] ? [] : DB::table('rental_payouts')->where('company_id', $cid)->whereIn('landlord_id', $owed)->whereNull('reversed_at')->whereBetween('paid_on', [$from, $to])->distinct()->pluck('landlord_id')->all();
            $toPay = count($owed) - count($paid);
            $items[] = self::line('landlords_paid', 'Landlords paid', $toPay === 0 ? 'Everyone owed has been paid this month' : number_format($toPay).' '.($toPay === 1 ? 'landlord' : 'landlords').' still to pay', ['type' => 'landlords', 'id' => null], ['done' => $toPay === 0]);
        }

        $ready = ! in_array(false, array_column($items, 'done'), true);
        $items[] = self::line('monthly_report', 'Monthly report', $ready ? 'Ready for '.$today->format('F') : $today->format('F').' so far, ready when the rest is done',
            ['type' => 'report', 'id' => null, 'params' => ['key' => 'monthly_building', 'month' => $today->format('Y-m')]], ['done' => $ready]);

        if (RentalAccess::can($this->by, 'rentals_close') && ($open = MonthClose::nextMonth($cid)) !== null) {
            $name = Carbon::parse($open.'-01')->format('F');
            $items[] = self::line('close_month', 'Close '.$name, $name.' has ended and can close. See it before it is final.', ['type' => 'month_end', 'id' => null, 'params' => ['month' => $open]], ['done' => false]);
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function lastMonthReport(): array
    {
        $last = $this->today->copy()->startOfMonth()->subMonth();
        // Nothing to report or close for a month before anyone had moved in (a new account's first days).
        if ($this->today->day > 10 || ! $this->table('rental_leases')->whereIn('status', RentalLease::LIVE)->where('start_date', '<=', $last->copy()->endOfMonth()->toDateString())->exists()) {
            return [];
        }
        $lines = [self::line('last_month_report', $last->format('F').'’s report is ready.', 'Monthly report', ['type' => 'report', 'id' => null, 'params' => ['key' => 'monthly_building', 'month' => $last->format('Y-m')]])];
        if (RentalAccess::can($this->by, 'rentals_close') && MonthClose::nextMonth($this->cid) === $last->format('Y-m')) {
            $lines[] = self::line('close_month', 'Close '.$last->format('F'), $last->format('F').' has ended and can close. See it before it is final.', ['type' => 'month_end', 'id' => null, 'params' => ['month' => $last->format('Y-m')]], ['done' => false]);
        }

        return $lines;
    }

    // ── Inside ──

    /** A table of the account narrowed to the login's properties. */
    private function table(string $table, string $alias = '', string $column = 'property_id'): Builder
    {
        $prefix = $alias !== '' ? "{$alias}." : '';

        return RentalAccess::scope(DB::table($alias !== '' ? "{$table} as {$alias}" : $table)->where($prefix.'company_id', $this->cid), $this->by, $prefix.$column);
    }

    /**
     * One line of work.
     *
     * @param  array{type: string, id: ?int, params?: array<string, string>}  $link
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private static function line(string $kind, string $title, string $detail, array $link, array $more = []): array
    {
        return array_replace(['kind' => $kind, 'title' => $title, 'detail' => $detail, 'amount_minor' => null, 'overdue' => false, 'promise_on' => null, 'tenant_id' => null, 'next_step' => null, 'done' => null, 'link' => $link], $more);
    }

    private static function days(int $n): string
    {
        return number_format($n).' '.($n === 1 ? 'day' : 'days');
    }

    /** A rough figure for a sentence (UGX 8.2M): for reading, never for sums. */
    private function short(int $minor): string
    {
        $major = intdiv($minor, 10 ** Minor::decimals($this->cid));
        if ($major < 1_000_000) {
            return Minor::format($minor, $this->cid);
        }
        $tenths = (int) round($major / 100_000);
        $words = $tenths % 10 === 0 ? (string) intdiv($tenths, 10) : number_format($tenths / 10, 1);

        return (string) RentalsModule::settings($this->cid)->currency.' '.$words.'M';
    }
}

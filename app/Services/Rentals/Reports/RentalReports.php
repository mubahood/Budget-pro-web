<?php

namespace App\Services\Rentals\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalCharge;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalMaintenance;
use App\Models\RentalPayment;
use App\Models\RentalProperty;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Collections\Arrears;
use App\Services\Rentals\Collections\FollowUps;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Payments\CashGuard;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Day;
use App\Support\Rentals\Minor;
use App\Support\Rentals\Periods;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Estate Ledger's reports (docs/estate-ledger/04-architecture.md §17). Each report is a table: columns (key, label, type), rows,
 * totals, and sometimes sections (smaller tables below it: collections by property, method and collector; a
 * tenant's deposit). Every amount is a `*_minor` integer taken from the journal, the allocations or the bills (never
 * worked out again by hand), so a report always agrees with the screens and with rentals:check. RentalExport writes
 * any of them as CSV, XLSX or PDF.
 *
 * Column types: text, money (minor units), int, date (Y-m-d), pct (basis points: 10000 = 100 %).
 *
 * Filters a report takes (`registry()` lists them per report; `run()` reads them from $params):
 *  - month:    month (Y-m; this month by default): the whole calendar month, its last day included
 *  - year:     year (Y; this year by default): the whole calendar year
 *  - period:   from, to (Y-m-d; the month so far by default)
 *  - property: property_id
 *  - landlord: landlord_id
 *  - as_of:    as_of (Y-m-d, today by default), with `days` after it (lease expiries)
 *  - lease:    lease_id (the tenant statement)
 * The cash book also takes `method` (cash, bank, mobile_money, cheque).
 *
 * A list screen's Export CSV opens the matching report with the list's own filters, so the file holds what the list
 * shows: `q` (its search) on rent_roll, arrears_aging, expenses, tenant_list, lease_list and receipts; `state` on rent_roll;
 * `bucket` on arrears_aging; `status` on expenses, tenant_list and lease_list; `category` on expenses; `method` and `view`
 * on receipts. A value a report does not know means no narrowing.
 *
 * A caretaker (RentalAccess::propertyIds) sees only their properties' rows; the reports that cover the whole
 * account's money (landlord statement, commission, cash book, income statement) are refused to them. Reports need
 * rentals_reports when a person asks; the system (null) may run any.
 *
 * A report may live in its own class (HANDLERS): the same method name, taking the same context, public. A new report's page setup
 * goes in its own `paper` key (RentalExport).
 */
final class RentalReports
{
    use ReportTable;

    /** key => [title, description, filters, scope: property (narrowed for a caretaker) | account (refused to one), agency only, gate if any] */
    public const REPORTS = [
        'monthly_building' => ['Monthly building report', 'One page for a month: income by kind, deposits kept apart, the expenses and the final balance.', ['month', 'property'], 'property', false],
        'rent_roll' => ['Rent roll', 'Every unit with its tenant, rent, deposit held, paid-up-to date and what is owed today.', ['property', 'landlord'], 'property', false],
        'collections' => ['Collections', 'Money received from tenants, day by day, with the totals by property, by method and by who received it.', ['period', 'property', 'landlord'], 'property', false],
        'arrears_aging' => ['Arrears aging', 'What each tenant owes past the grace days, by how long it is overdue: 0–30, 31–60, 61–90 and over 90 days.', ['property', 'landlord'], 'property', false],
        'occupancy' => ['Occupancy', 'Units, occupied and vacant per property, the days units stood empty in the period and the rent lost at the asking rent.', ['period', 'property', 'landlord'], 'property', false],
        'landlord_statement' => ['Landlord statement', 'A landlord\'s account for the period: rent collected less commission, expenses, payouts and the balance.', ['period', 'landlord'], 'account', false],
        'commission' => ['Commission', 'Commission earned on the rent collected in the period, per landlord and property.', ['period', 'property', 'landlord'], 'account', true],
        'expenses' => ['Expenses', 'Money paid out in the period, charged to landlords or kept as the agency\'s own costs.', ['period', 'property', 'landlord'], 'property', false],
        'deposits_held' => ['Deposits held', 'Security deposits held for tenants today, per lease, by the agency or by the landlord.', ['property', 'landlord'], 'property', false],
        'deposit_register' => ['Deposit register', 'Each tenant\'s deposit: received, used for what was owed, refunded and held today.', ['property', 'landlord'], 'property', false],
        'cash_book' => ['Cash book', 'Every amount in and out of cash, bank, mobile money and cheques in the period, with the balance after each.', ['period'], 'account', false],
        'tenant_statement' => ['Tenant statement', 'One tenancy\'s bills and payments with the running balance, and its deposit.', ['lease', 'period'], 'property', false],
        'lease_expiries' => ['Lease expiries', 'Leases whose fixed term ends, or whose tenant moves out, in the coming days.', ['as_of', 'property', 'landlord'], 'property', false],
        'maintenance' => ['Maintenance', 'Repair requests opened in the period or still open, with their status and what they cost.', ['period', 'property'], 'property', false],
        'income_statement' => ['Income statement', 'The agency\'s income (commission and the fees it keeps) less its own expenses.', ['period'], 'account', false],
        'tenant_list' => ['Tenants', 'Everyone who rents or rented: their phone, the units they hold and what they owe.', ['property', 'landlord'], 'property', false],
        'lease_list' => ['Leases', 'Who rents which unit, from when, at what rent, with what is owed and the paid-up-to date.', ['property', 'landlord'], 'property', false],
        'receipts' => ['Receipts', 'Every receipt in the period: who paid, for which unit, how, and anything paid ahead.', ['period', 'property', 'landlord'], 'property', false],
        'landlord_rent_sheet' => ['Landlord rent sheet', 'One landlord\'s units for a month: tenant, rent, billed, paid, owed at the end, months behind and paid up to.', ['month', 'landlord'], 'account', true],
        'landlord_advances' => ['Landlord advances', 'Landlords below zero now: since when, how much, last collected and about how long it takes to clear.', [], 'account', true],
        'money_position' => ['Whose money this is', 'Cash in all accounts against what is owed to landlords, deposits, rent paid ahead and the agency\'s own money, on any day.', ['as_of'], 'account', true],
        'landlord_year' => ['Year summary', 'A landlord\'s year: collected by month and property, commission, expenses, deposits, payouts, opening and closing. A summary of money handled, not tax advice.', ['year', 'landlord'], 'account', false],
        'journal_export' => ['Journal for the accountant', 'One line per posting per account for the period, with debits and credits.', ['period'], 'account', false],
        'trial_balance' => ['Trial balance', 'Opening, movement and closing per account for the period, with a subtotal per landlord.', ['period'], 'account', false],
    ];

    /** Reports whose method lives in a class of its own: key => class. */
    private const HANDLERS = ['landlord_statement' => StatementReport::class, 'commission' => CommissionReport::class,
        'landlord_rent_sheet' => LandlordWorkReports::class, 'landlord_advances' => LandlordWorkReports::class,
        'money_position' => LandlordReports::class, 'landlord_year' => LandlordReports::class, 'journal_export' => LandlordReports::class, 'trial_balance' => LandlordReports::class];

    /** Journal row types as people read them. */
    public const ENTRY_LABELS = [
        'collected' => 'Collected', 'prepaid' => 'Paid ahead', 'credit_applied' => 'Credit applied', 'deposit_applied' => 'Paid from the deposit',
        'unapplied' => 'Back to credit', 'deposit_refund' => 'Deposit refunded', 'deposit_refund_landlord' => 'Deposit refunded for the landlord',
        'credit_refund' => 'Credit refunded', 'opening_credit' => 'Credit brought forward', 'opening_deposit' => 'Deposit brought forward',
        'opening_cash' => 'Opening balance', 'opening_landlord' => 'Opening balance', 'payout' => 'Payout', 'expense_landlord' => 'Expense',
        'expense_agency' => 'Agency expense', 'transfer' => 'Moved between accounts', 'reversal' => 'Correction',
        'agency_drawing' => 'Taken out by the agency', 'collected_direct' => 'Paid to the landlord directly',
    ];

    private const METHOD_LABELS = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_money' => 'Mobile money', 'cheque' => 'Cheque', 'deposit' => 'Deposit',
        'waiver' => 'Forgiven', 'opening' => 'Brought forward', RentalPayment::DIRECT => 'Paid to the landlord'];

    /** Journal rows in an owner's words (KR-06): no payouts, no agency. */
    private const OWNER_ENTRY_LABELS = ['payout' => 'Owner drawing', 'expense_agency' => 'Expense', 'deposit_refund_landlord' => 'Deposit refunded'];

    /** What an owner-run account has no use for (KR-06): the columns and totals that split money between an agency and its landlords. */
    private const OWNER_HIDDEN_COLUMNS = ['landlord', 'charged_to', 'held_by', 'commission_minor'];

    private const OWNER_HIDDEN_TOTALS = ['landlords_minor', 'agency_minor', 'held_by_agency_minor', 'held_by_landlords_minor', 'commission_minor'];

    /** Collection rows of the journal (and their corrections): the money tenants handed over. */
    private const COLLECTION_TYPES = ['collected', 'prepaid'];

    private const EXPENSE_TYPES = ['expense_landlord', 'expense_agency'];

    /**
     * The reports an account can run, with what each is for and the filters it takes. With an owner-mode account the
     * agency-only reports are left out and the statements are worded for an owner.
     *
     * @return list<array{key: string, title: string, description: string, filters: list<string>}>
     */
    public static function registry(?int $companyId = null): array
    {
        $owner = $companyId !== null && RentalsModule::isOwnerMode($companyId);
        $out = [];
        foreach (self::REPORTS as $key => [$title, $description, $filters, , $agencyOnly]) {
            if (($owner && $agencyOnly) || ! self::open($key)) {
                continue;
            }
            if ($owner) {
                [$title, $description, $filters] = self::ownerWords($key, $title, $description, $filters);
            }
            $out[] = ['key' => $key, 'title' => $title, 'description' => $description, 'filters' => $filters];
        }

        return $out;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::REPORTS);
    }

    /** The one record a report cannot run without: `landlord`, `lease`, or null. An owner-run account has one landlord: itself. */
    public static function needs(string $key, bool $owner): ?string
    {
        return match (true) {
            $key === 'tenant_statement' => 'lease',
            $key === 'landlord_rent_sheet', ! $owner && in_array($key, ['landlord_statement', 'landlord_year'], true) => 'landlord',
            default => null,
        };
    }

    /**
     * Refused in the words the report itself would use, before any link to its file is made.
     *
     * @param  array<string, mixed>  $params
     */
    public static function refuseWithout(string $key, bool $owner, array $params): void
    {
        $needs = self::needs($key, $owner);
        if ($needs === 'landlord' && empty($params['landlord_id'])) {
            throw BusinessRuleException::make('landlord_required', 'Choose the landlord.');
        }
        if ($needs === 'lease' && empty($params['lease_id'])) {
            throw BusinessRuleException::make('lease_required', 'Choose the tenancy (lease).');
        }
    }

    /** Whether a report exists. */
    public static function open(string $key): bool
    {
        return isset(self::REPORTS[$key]);
    }

    /**
     * Run a report.
     *
     * @param  array<string, mixed>  $params  see the filters above
     * @return array{key: string, title: string, subtitle: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, totals: array<string, mixed>, sections: list<array<string, mixed>>, currency: string, decimals: int, from: ?string, to: ?string, account: array{id: int, name: string}, generated_at: string, landlord?: array<string, mixed>, lease?: array<string, mixed>, figures?: array<string, mixed>, one_page?: bool, total_label?: string, paper?: array<string, mixed>}
     */
    public function run(string $key, int $companyId, ?User $by, array $params = []): array
    {
        if (! self::open($key)) {
            throw BusinessRuleException::make('unknown_report', "There is no report called \"{$key}\".");
        }
        [$title, , , $scope, $agencyOnly] = self::REPORTS[$key];
        if ($by !== null) {
            RentalAccess::authorize($by, 'rentals_reports');
            if ((int) $by->company_id !== $companyId) {
                throw BusinessRuleException::make('not_found', 'That report was not found.');
            }
            if ($scope === 'account' && RentalAccess::propertyIds($by) !== null) {
                throw BusinessRuleException::make('forbidden', 'This report covers the whole account\'s money. Ask your property manager for it.');
            }
        }
        $settings = RentalsModule::settings($companyId);
        $owner = $settings->mode === 'owner';
        if ($owner && $agencyOnly) {
            throw BusinessRuleException::make('agency_only', 'This report is for agencies that manage property for landlords.');
        }
        if ($owner) {
            [$title] = self::ownerWords($key, $title, '', []);
        }
        $ctx = $this->context($companyId, $by, $params, $key, $owner);
        $method = lcfirst(str_replace('_', '', ucwords($key, '_')));
        $handler = self::HANDLERS[$key] ?? null;
        $body = ($handler !== null ? new $handler : $this)->{$method}($ctx);
        if ($owner) {
            $body = self::ownerBody($body);
        }
        $company = Company::withoutGlobalScopes()->find($companyId);

        return [
            'key' => $key, 'title' => $body['title'] ?? $title, 'subtitle' => $body['subtitle'] ?? $this->subtitle($ctx),
            'columns' => $body['columns'], 'rows' => array_values($body['rows']), 'totals' => $body['totals'] ?? [], 'sections' => $body['sections'] ?? [],
            'currency' => (string) $settings->currency, 'decimals' => Minor::decimals($companyId), 'from' => $body['from'] ?? $ctx->from, 'to' => $body['to'] ?? $ctx->to,
            'account' => ['id' => $companyId, 'name' => (string) ($company?->name ?? '')], 'generated_at' => now()->toIso8601String(),
        ] + array_intersect_key($body, array_flip(['landlord', 'lease', 'figures', 'one_page', 'total_label', 'paper']));
    }

    // ── The reports ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function rentRoll(object $x): array
    {
        $state = (string) ($x->params['state'] ?? 'all');
        $units = RentalUnit::withoutGlobalScopes()->where('company_id', $x->cid)->where('status', $state === 'off' ? '=' : '!=', 'inactive')
            ->when($x->ids !== null, fn ($q) => $q->whereIn('property_id', $x->ids ?: [0]))->get();
        $props = $this->names('rental_properties', 'name', $units->pluck('property_id')->all(), $x->cid);
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $x->cid)->whereIn('status', RentalLease::LIVE)
            ->whereIn('unit_id', $units->pluck('id')->all() ?: [0])->get()->keyBy('unit_id');
        $tenants = DB::table('rental_tenants')->where('company_id', $x->cid)->whereIn('id', $leases->pluck('tenant_id')->all() ?: [0])->get(['id', 'name', 'phone'])->keyBy('id');
        $positions = Leases::positions($leases->all(), $x->today);
        $kinds = (array) config('rentals.unit_kinds');
        $rows = [];
        foreach ($units as $u) {
            $lease = $leases->get($u->id);
            $pos = $lease !== null ? $positions[(int) $lease->id] : null;
            $t = $lease !== null ? $tenants->get($lease->tenant_id) : null;
            $keep = match ($state) {
                'vacant' => $lease === null && $u->status === 'available',
                'occupied' => $lease !== null,
                'overdue' => $lease !== null && ($pos['overdue_minor'] ?? 0) > 0,
                'notice' => $lease !== null && $lease->status === 'notice',
                'unavailable' => $lease === null && in_array($u->status, ['reserved', 'maintenance', 'in_use'], true),
                default => true,
            };
            if (! $keep || ! self::matches($x, [(string) $u->code, $props[$u->property_id] ?? '', (string) $t?->name])) {
                continue;
            }
            $rows[] = ['property' => $props[$u->property_id] ?? '', 'unit' => (string) $u->code, 'kind' => $kinds[$u->kind] ?? (string) $u->kind,
                'state' => $lease === null && $u->status === 'in_use' ? 'Used by '.$u->in_use_by : ucfirst(Units::state($u, $lease, $pos)), 'tenant' => $t?->name, 'phone' => $t?->phone, 'lease' => $lease?->number,
                'since' => $lease?->start_date?->toDateString(), 'cycle_months' => $lease !== null ? (int) $lease->cycle_months : null,
                'asking_minor' => (int) $u->rent_minor, 'rent_minor' => $lease !== null ? (int) $lease->rent_minor : null,
                'deposit_held_minor' => $pos['deposit_held_minor'] ?? null, 'paid_up_to' => $pos['paid_up_to'] ?? null,
                'owed_minor' => $pos['owed_minor'] ?? null, 'overdue_minor' => $pos['overdue_minor'] ?? null,
                '_peek' => $lease !== null ? 'lease:'.$lease->id : 'unit:'.$u->id];
        }
        usort($rows, fn ($a, $b) => [$a['property'], 0] <=> [$b['property'], 0] ?: strnatcasecmp($a['unit'], $b['unit']));
        $let = array_filter($rows, fn ($r) => $r['lease'] !== null);
        $inUse = count(array_filter($rows, fn ($r) => str_starts_with((string) $r['state'], 'Used by ')));

        return [
            'subtitle' => $this->asAt($x),
            'columns' => [self::c('property', 'Property'), self::c('unit', 'Unit'), self::c('kind', 'Kind'), self::c('state', 'State'), self::c('tenant', 'Tenant'),
                self::c('phone', 'Phone'), self::c('lease', 'Lease'), self::c('since', 'Since', 'date'), self::c('cycle_months', 'Months per bill', 'int'),
                self::c('asking_minor', 'Asking rent', 'money'), self::c('rent_minor', 'Rent', 'money'), self::c('deposit_held_minor', 'Deposit held', 'money'),
                self::c('paid_up_to', 'Paid up to', 'date'), self::c('owed_minor', 'Owed', 'money'), self::c('overdue_minor', 'Overdue', 'money')],
            'rows' => $rows,
            'totals' => ['rent_minor' => array_sum(array_column($let, 'rent_minor')), 'deposit_held_minor' => array_sum(array_column($let, 'deposit_held_minor')),
                'owed_minor' => array_sum(array_column($let, 'owed_minor')), 'overdue_minor' => array_sum(array_column($let, 'overdue_minor')),
                'units' => count($rows), 'occupied' => count($let), 'vacant' => count($rows) - count($let) - $inUse] + ($inUse > 0 ? ['in_use' => $inUse] : []),
        ];
    }

    /** @return array<string, mixed> */
    private function collections(object $x): array
    {
        $list = DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')
            ->join('rental_payments as p', 'p.id', '=', 'e.payment_id')
            ->where('e.company_id', $x->cid)->whereBetween('e.occurred_on', [$x->from, $x->to])->where('e.cash_minor', '!=', 0)
            ->where(fn ($q) => $q->whereIn('e.type', self::COLLECTION_TYPES)->orWhere(fn ($q) => $q->where('e.type', 'reversal')->whereIn('o.type', self::COLLECTION_TYPES)))
            ->when($x->ids !== null, fn ($q) => $q->whereIn('e.property_id', $x->ids ?: [0]))
            ->orderBy('e.occurred_on')->orderBy('e.id')
            ->get(['e.occurred_on', 'e.type', 'e.method', 'e.cash_minor', 'e.property_id', 'e.payment_id', 'p.received_by_id', 'p.channel']);
        $props = $this->names('rental_properties', 'name', $list->pluck('property_id')->all(), $x->cid);
        $people = DB::table('admin_users')->whereIn('id', $list->pluck('received_by_id')->filter()->unique()->all() ?: [0])->pluck('name', 'id')->all();
        $methods = CashBook::METHODS;
        $days = [];
        $dayReceipts = [];
        $groups = ['property' => [], 'method' => [], 'collector' => []];
        foreach ($list as $e) {
            $day = substr((string) $e->occurred_on, 0, 10);
            $amount = (int) $e->cash_minor;
            $receipt = $e->type !== 'reversal' ? (int) $e->payment_id : null;
            $d = &$days[$day];
            $d ??= ['date' => $day] + array_fill_keys(array_map(fn ($m) => "{$m}_minor", $methods), 0) + ['total_minor' => 0];
            $d[$e->method.'_minor'] = ($d[$e->method.'_minor'] ?? 0) + $amount;
            $d['total_minor'] += $amount;
            unset($d);
            if ($receipt !== null) {
                $dayReceipts[$day][$receipt] = true;
            }
            $collector = $e->received_by_id !== null ? ($people[$e->received_by_id] ?? 'Staff') : ($e->channel === 'portal' ? 'Tenant (own sign-in)' : 'System');
            foreach (['property' => $props[$e->property_id] ?? 'No property', 'method' => self::METHOD_LABELS[$e->method] ?? (string) $e->method, 'collector' => $collector] as $g => $name) {
                $r = &$groups[$g][$name];
                $r ??= ['name' => $name, 'receipts' => [], 'total_minor' => 0];
                $r['total_minor'] += $amount;
                if ($receipt !== null) {
                    $r['receipts'][$receipt] = true;
                }
                unset($r);
            }
        }
        $count = fn (array $rows) => array_map(fn ($r) => ['receipts' => count($r['receipts'])] + $r, $rows);
        $rows = array_map(fn ($d) => ['receipts' => count($dayReceipts[$d['date']] ?? [])] + $d, array_values($days));
        $sections = [];
        foreach (['property' => 'By property', 'method' => 'By method', 'collector' => 'By who received it'] as $g => $title) {
            $grows = $count(array_values($groups[$g]));
            usort($grows, fn ($a, $b) => $b['total_minor'] <=> $a['total_minor']);
            $sections[] = ['title' => $title, 'columns' => [self::c('name', ucfirst($g === 'collector' ? 'Received by' : $g)), self::c('receipts', 'Receipts', 'int'),
                self::c('total_minor', 'Amount', 'money')], 'rows' => $grows,
                'totals' => ['receipts' => array_sum(array_column($grows, 'receipts')), 'total_minor' => array_sum(array_column($grows, 'total_minor'))]];
        }
        $totals = ['receipts' => count(array_unique(array_merge([], ...array_map(fn ($r) => array_keys($r), array_values($dayReceipts)))))];
        foreach ($methods as $m) {
            $totals["{$m}_minor"] = array_sum(array_column($rows, "{$m}_minor"));
        }
        $totals['total_minor'] = array_sum(array_column($rows, 'total_minor'));

        return [
            'columns' => [self::c('date', 'Date', 'date'), self::c('receipts', 'Receipts', 'int'), self::c('cash_minor', 'Cash', 'money'), self::c('mobile_money_minor', 'Mobile money', 'money'),
                self::c('bank_minor', 'Bank', 'money'), self::c('cheque_minor', 'Cheque', 'money'), self::c('total_minor', 'Total', 'money')],
            'rows' => $rows,
            'totals' => $totals, 'sections' => $sections,
        ];
    }

    /** @return array<string, mixed> */
    private function arrearsAging(object $x): array
    {
        $byLease = Arrears::byLease($x->cid, $x->by, null, $x->today);
        $meta = DB::table('rental_leases as l')->join('rental_tenants as t', 't.id', '=', 'l.tenant_id')->join('rental_units as u', 'u.id', '=', 'l.unit_id')
            ->join('rental_properties as p', 'p.id', '=', 'l.property_id')->where('l.company_id', $x->cid)->whereIn('l.id', array_keys($byLease) ?: [0])
            ->get(['l.id', 'l.number', 'l.status', 'l.property_id', 't.name as tenant', 't.phone', 'u.code as unit', 'p.name as property'])->keyBy('id');
        $follow = [];
        foreach (Arrears::list($x->cid, $x->by, [], $x->today) as $a) {
            $follow[$a['lease_id']] = $a['last_follow_up'];
        }
        $rows = [];
        foreach ($byLease as $leaseId => $b) {
            $m = $meta->get($leaseId);
            if ($m === null || ($x->ids !== null && ! in_array((int) $m->property_id, $x->ids, true))) {
                continue;
            }
            $f = $follow[$leaseId] ?? null;
            $bucket = (string) ($x->params['bucket'] ?? '');
            $inBucket = match (true) {
                $bucket === 'promise' => ($f['promise_on'] ?? null) !== null && $f['promise_on'] <= $x->today->toDateString(),
                isset(Arrears::BUCKETS[$bucket]) => (int) $b[$bucket] > 0,
                default => true,
            };
            if (! $inBucket || ! self::matches($x, [(string) $m->tenant, (string) $m->phone, (string) $m->unit, (string) $m->property, (string) $m->number])) {
                continue;
            }
            $rows[] = ['tenant' => $m->tenant, 'phone' => $m->phone, 'unit' => $m->unit, 'property' => $m->property, 'lease' => $m->number,
                'status' => $m->status === 'ended' ? 'Moved out' : ($m->status === 'notice' ? 'Under notice' : 'Staying'), 'days' => $b['days'],
                'd0_30_minor' => $b['d0_30'], 'd31_60_minor' => $b['d31_60'], 'd61_90_minor' => $b['d61_90'], 'd90_plus_minor' => $b['d90_plus'], 'total_minor' => $b['total_minor'],
                'last_follow_up' => $f ? trim((FollowUps::CHANNELS[$f['channel']] ?? ucfirst((string) $f['channel'])).' '.Carbon::parse((string) $f['at'], 'UTC')->setTimezone(LocalDate::timezone($x->cid))->format('j M Y').($f['note'] ? ': '.$f['note'] : '')) : null,
                'promise_on' => $f['promise_on'] ?? null, '_peek' => 'lease:'.$leaseId];
        }
        usort($rows, fn ($a, $b) => [$b['total_minor'], $b['days']] <=> [$a['total_minor'], $a['days']]);
        $sum = fn (string $k) => array_sum(array_column($rows, $k));

        return [
            'subtitle' => $this->asAt($x).' · overdue after '.(int) RentalsModule::settings($x->cid)->grace_days.' grace days',
            'columns' => [self::c('tenant', 'Tenant'), self::c('phone', 'Phone'), self::c('unit', 'Unit'), self::c('property', 'Property'), self::c('lease', 'Lease'),
                self::c('status', 'Tenancy'), self::c('days', 'Days overdue', 'int'), self::c('d0_30_minor', '0–30 days', 'money'), self::c('d31_60_minor', '31–60 days', 'money'),
                self::c('d61_90_minor', '61–90 days', 'money'), self::c('d90_plus_minor', 'Over 90 days', 'money'), self::c('total_minor', 'Total', 'money'),
                self::c('last_follow_up', 'Last follow-up'), self::c('promise_on', 'Promised for', 'date')],
            'rows' => $rows,
            'totals' => ['d0_30_minor' => $sum('d0_30_minor'), 'd31_60_minor' => $sum('d31_60_minor'), 'd61_90_minor' => $sum('d61_90_minor'),
                'd90_plus_minor' => $sum('d90_plus_minor'), 'total_minor' => $sum('total_minor'), 'leases' => count($rows)],
        ];
    }

    /** @return array<string, mixed> */
    private function occupancy(object $x): array
    {
        $end = min($x->to, $x->today->toDateString());
        $from = $x->from;
        $props = RentalProperty::withoutGlobalScopes()->where('company_id', $x->cid)->where('status', 'active')
            ->when($x->ids !== null, fn ($q) => $q->whereIn('id', $x->ids ?: [0]))->orderBy('name')->get(['id', 'name']);
        $units = RentalUnit::withoutGlobalScopes()->where('company_id', $x->cid)->whereNotIn('status', ['inactive', 'in_use'])->whereIn('property_id', $props->pluck('id')->all() ?: [0])
            ->get(['id', 'property_id', 'rent_minor', 'created_at']);
        // A room the landlord or caretaker uses (LC-21) is not for letting: not a unit here, not vacant, and no rent lost.
        $inUse = (int) RentalUnit::withoutGlobalScopes()->where('company_id', $x->cid)->where('status', 'in_use')->whereIn('property_id', $props->pluck('id')->all() ?: [0])->count();
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $x->cid)->where('status', '!=', 'cancelled')->whereIn('unit_id', $units->pluck('id')->all() ?: [0])
            ->where('start_date', '<=', $end)->where(fn ($q) => $q->whereNull('ended_on')->orWhere('ended_on', '>=', $from))
            ->get(['unit_id', 'start_date', 'ended_on'])->groupBy('unit_id');
        $tz = LocalDate::timezone($x->cid);
        $rows = [];
        foreach ($props as $p) {
            $r = ['property' => (string) $p->name, 'units' => 0, 'occupied' => 0, 'vacant' => 0, 'occupancy_bp' => 0, 'vacancy_days' => 0, 'rent_lost_minor' => 0, '_peek' => 'property:'.$p->id];
            foreach ($units->where('property_id', $p->id) as $u) {
                $r['units']++;
                $covered = [];
                $occupied = false;
                foreach ($leases->get($u->id, collect()) as $l) {
                    $a = $l->start_date->toDateString();
                    $b = $l->ended_on?->toDateString() ?? $end;
                    $covered[] = [$a, $b];
                    $occupied = $occupied || ($a <= $end && $b >= $end);
                }
                $r[$occupied ? 'occupied' : 'vacant']++;
                $since = $u->created_at !== null ? max($from, Carbon::parse($u->created_at)->setTimezone($tz)->toDateString()) : $from;
                foreach (self::gaps($since, $end, $covered) as [$a, $b]) {
                    $r['vacancy_days'] += Periods::days(Carbon::parse($a), Carbon::parse($b)) + 1;
                    $r['rent_lost_minor'] += self::rentFor((int) $u->rent_minor, $a, $b);
                }
            }
            $r['occupancy_bp'] = $r['units'] > 0 ? intdiv($r['occupied'] * 10000 + intdiv($r['units'], 2), $r['units']) : 0;
            $rows[] = $r;
        }
        $sum = fn (string $k) => array_sum(array_column($rows, $k));
        $units = $sum('units');

        return [
            'subtitle' => $this->period($x).' · occupied and vacant on '.Carbon::parse($end)->format('j M Y'),
            'columns' => [self::c('property', 'Property'), self::c('units', 'Units', 'int'), self::c('occupied', 'Occupied', 'int'), self::c('vacant', 'Vacant', 'int'),
                self::c('occupancy_bp', 'Occupancy %', 'pct') + ['places' => 1], self::c('vacancy_days', 'Days vacant', 'int'), self::c('rent_lost_minor', 'Rent lost', 'money')],
            'rows' => $rows,
            'totals' => ['units' => $units, 'occupied' => $sum('occupied'), 'vacant' => $sum('vacant'),
                'occupancy_bp' => $units > 0 ? intdiv($sum('occupied') * 10000 + intdiv($units, 2), $units) : 0,
                'vacancy_days' => $sum('vacancy_days'), 'rent_lost_minor' => $sum('rent_lost_minor')] + ($inUse > 0 ? ['in_use' => $inUse] : []),
            'sections' => $inUse === 0 ? [] : [['title' => 'Left out', 'columns' => [self::c('line', 'Note')], 'rows' => [['line' => $inUse.' '.($inUse === 1 ? 'room' : 'rooms').' used by the landlord or a caretaker: neither let, nor vacant, nor rent lost.']], 'totals' => []]],
        ];
    }

    /** @return array<string, mixed> */
    private function expenses(object $x): array
    {
        $list = DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')
            ->where('e.company_id', $x->cid)->whereBetween('e.occurred_on', [$x->from, $x->to])
            ->where(fn ($q) => $q->whereIn('e.type', self::EXPENSE_TYPES)->orWhere(fn ($q) => $q->where('e.type', 'reversal')->whereIn('o.type', self::EXPENSE_TYPES)))
            // A landlord chosen also finds what is charged to them with no property to name (money held back).
            ->when($x->ids !== null, fn ($q) => $x->landlord !== null && $x->property === null
                ? $q->where(fn ($w) => $w->whereIn('e.property_id', $x->ids ?: [0])->orWhere(fn ($n) => $n->whereNull('e.property_id')->where('e.landlord_id', (int) $x->landlord->id)))
                : $q->whereIn('e.property_id', $x->ids ?: [0]))
            ->orderBy('e.occurred_on')->orderBy('e.id')
            ->get(['e.id', 'e.occurred_on', 'e.type', 'o.type as original_type', 'e.method', 'e.cash_minor', 'e.expense_id', 'e.property_id', 'e.landlord_id']);
        $expenses = DB::table('rental_expenses')->where('company_id', $x->cid)->whereIn('id', $list->pluck('expense_id')->filter()->all() ?: [0])
            ->get(['id', 'number', 'category', 'description', 'vendor', 'unit_id', 'charged_to', 'reversed_at'])->keyBy('id');
        $props = $this->names('rental_properties', 'name', $list->pluck('property_id')->all(), $x->cid);
        $landlords = $this->names('rental_landlords', 'name', $list->pluck('landlord_id')->all(), $x->cid);
        $status = (string) ($x->params['status'] ?? 'all');
        $onlyCategory = trim((string) ($x->params['category'] ?? ''));
        $rows = [];
        $byCategory = [];
        foreach ($list as $e) {
            $ex = $expenses->get($e->expense_id);
            $keep = match ($status) { // a reversed expense stays together with its reversal, so the two always cancel out
                'live' => $ex?->reversed_at === null,
                'landlord', 'agency' => $ex?->reversed_at === null && $ex?->charged_to === $status,
                'reversed' => $ex?->reversed_at !== null,
                default => true,
            };
            if (! $keep || ($onlyCategory !== '' && (string) ($ex->category ?? '') !== $onlyCategory)
                || ! self::matches($x, [(string) ($ex->number ?? ''), (string) ($ex->description ?? ''), (string) ($ex->vendor ?? ''), (string) ($ex->category ?? ''), (string) ($props[$e->property_id] ?? '')])) {
                continue;
            }
            $amount = -(int) $e->cash_minor;
            $toLandlord = ($e->type === 'reversal' ? $e->original_type : $e->type) === 'expense_landlord';
            $category = (string) ($ex->category ?? 'Other');
            $rows[] = ['date' => substr((string) $e->occurred_on, 0, 10), 'number' => $ex->number ?? null, 'category' => $category,
                'description' => ($e->type === 'reversal' ? 'Reversed: ' : '').($ex->description ?? ''), 'vendor' => $ex->vendor ?? null,
                'property' => $props[$e->property_id] ?? null, 'charged_to' => $toLandlord ? ($landlords[$e->landlord_id] ?? 'Landlord') : 'Agency',
                'method' => self::METHOD_LABELS[$e->method] ?? (string) $e->method, 'amount_minor' => $amount, '_landlord' => $toLandlord, '_peek' => $e->expense_id ? 'expense:'.$e->expense_id : null];
            $g = $byCategory[$category] ?? ['category' => $category, 'count' => 0, 'amount_minor' => 0];
            $g['count'] += $e->type === 'reversal' ? 0 : 1;
            $g['amount_minor'] += $amount;
            $byCategory[$category] = $g;
        }
        ksort($byCategory);
        $landlordTotal = array_sum(array_map(fn ($r) => $r['_landlord'] ? $r['amount_minor'] : 0, $rows));
        $total = array_sum(array_column($rows, 'amount_minor'));
        $rows = array_map(fn ($r) => array_diff_key($r, ['_landlord' => 1]), $rows);
        $cats = array_values($byCategory);

        return [
            'title' => $x->owner ? 'Expenses' : null,
            'columns' => [self::c('date', 'Date', 'date'), self::c('number', 'Number'), self::c('category', 'Category'), self::c('description', 'Description'),
                self::c('vendor', 'Paid to'), self::c('property', 'Property'), self::c('charged_to', 'Charged to'), self::c('method', 'Paid by'), self::c('amount_minor', 'Amount', 'money')],
            'rows' => $rows,
            'totals' => ['amount_minor' => $total, 'landlords_minor' => $landlordTotal, 'agency_minor' => $total - $landlordTotal],
            'sections' => [['title' => 'By category', 'columns' => [self::c('category', 'Category'), self::c('count', 'Expenses', 'int'), self::c('amount_minor', 'Amount', 'money')],
                'rows' => $cats, 'totals' => ['count' => array_sum(array_column($cats, 'count')), 'amount_minor' => array_sum(array_column($cats, 'amount_minor'))]]],
        ];
    }

    /** @return array<string, mixed> */
    private function depositsHeld(object $x): array
    {
        $withDeposit = RentalCharge::withoutGlobalScopes()->where('company_id', $x->cid)->where('kind', 'deposit')->select('lease_id');
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $x->cid)->where('status', '!=', 'cancelled')->whereIn('id', $withDeposit)
            ->when($x->ids !== null, fn ($q) => $q->whereIn('property_id', $x->ids ?: [0]))->orderBy('id')->get();
        $tenants = $this->names('rental_tenants', 'name', $leases->pluck('tenant_id')->all(), $x->cid);
        $units = $this->names('rental_units', 'code', $leases->pluck('unit_id')->all(), $x->cid);
        $props = $this->names('rental_properties', 'name', $leases->pluck('property_id')->all(), $x->cid);
        $landlords = $this->names('rental_landlords', 'name', $leases->pluck('landlord_id')->all(), $x->cid);
        $heldBy = Leases::depositsHeld($leases->all());
        $rows = [];
        foreach ($leases as $l) {
            $held = (int) $heldBy[(int) $l->id];
            if ($held <= 0) {
                continue;
            }
            $rows[] = ['lease' => $l->number, 'status' => $l->isLive() ? ($l->status === 'notice' ? 'Under notice' : 'Staying') : 'Moved out',
                'tenant' => $tenants[$l->tenant_id] ?? '', 'unit' => $units[$l->unit_id] ?? '', 'property' => $props[$l->property_id] ?? '',
                'landlord' => $landlords[$l->landlord_id] ?? '', 'held_by' => $l->deposit_held_by === 'landlord' ? 'Landlord' : ($x->owner ? 'You' : 'Agency'),
                'deposit_minor' => (int) $l->deposit_minor, 'held_minor' => $held, '_peek' => 'lease:'.$l->id];
        }
        usort($rows, fn ($a, $b) => [$a['property'], 0] <=> [$b['property'], 0] ?: strnatcasecmp($a['unit'], $b['unit']));
        $agency = array_sum(array_map(fn ($r) => $r['held_by'] === 'Landlord' ? 0 : $r['held_minor'], $rows));
        $total = array_sum(array_column($rows, 'held_minor'));

        return [
            'subtitle' => $this->asAt($x),
            'columns' => [self::c('lease', 'Lease'), self::c('status', 'Tenancy'), self::c('tenant', 'Tenant'), self::c('unit', 'Unit'), self::c('property', 'Property'),
                self::c('landlord', 'Landlord'), self::c('held_by', 'Held by'), self::c('deposit_minor', 'Deposit agreed', 'money'), self::c('held_minor', 'Held', 'money')],
            'rows' => $rows,
            'totals' => ['deposit_minor' => array_sum(array_column($rows, 'deposit_minor')), 'held_minor' => $total, 'held_by_agency_minor' => $agency,
                'held_by_landlords_minor' => $total - $agency, 'leases' => count($rows)],
        ];
    }

    /** @return array<string, mixed> */
    private function cashBook(object $x): array
    {
        $method = in_array($x->params['method'] ?? null, CashBook::METHODS, true) ? (string) $x->params['method'] : null;
        $st = CashBook::statement($x->cid, $x->from, $x->to, $method, 1_000_000);
        $rows = [['date' => $x->from, 'kind' => '', 'description' => 'Opening balance', 'method' => $method !== null ? CashGuard::LABELS[$method] : null,
            'in_minor' => null, 'out_minor' => null, 'balance_minor' => (int) $st['opening_minor']]];
        foreach ($st['rows'] as $r) {
            $rows[] = ['date' => $r['date'], 'kind' => ($x->owner ? (self::OWNER_ENTRY_LABELS[$r['type']] ?? null) : null) ?? self::ENTRY_LABELS[$r['type']] ?? (string) $r['type'], 'description' => $r['description'],
                'method' => CashGuard::LABELS[$r['method']] ?? (string) $r['method'], 'in_minor' => (int) $r['in_minor'], 'out_minor' => (int) $r['out_minor'],
                'balance_minor' => (int) $r['balance_minor'], '_peek' => match (true) {
                    ! empty($r['payment_id']) => 'payment:'.$r['payment_id'], ! empty($r['expense_id']) => 'expense:'.$r['expense_id'], ! empty($r['payout_id']) => 'payout:'.$r['payout_id'],
                    $r['type'] === 'transfer' => 'transfer:'.$r['id'], ! empty($r['lease_id']) => 'lease:'.$r['lease_id'], default => null,
                }];
        }
        $by = DB::table('rental_entries')->where('company_id', $x->cid)->where('cash_minor', '!=', 0)->where('occurred_on', '<=', $x->to)->whereNotNull('method')
            ->when($method !== null, fn ($q) => $q->where('method', $method))->groupBy('method')
            ->selectRaw('method, SUM(CASE WHEN occurred_on < ? THEN cash_minor ELSE 0 END) AS opening, SUM(CASE WHEN occurred_on >= ? AND cash_minor > 0 THEN cash_minor ELSE 0 END) AS cash_in, '
                .'SUM(CASE WHEN occurred_on >= ? AND cash_minor < 0 THEN -cash_minor ELSE 0 END) AS cash_out, SUM(cash_minor) AS closing', [$x->from, $x->from, $x->from])
            ->get()->keyBy('method');
        $methods = [];
        foreach (CashBook::METHODS as $m) {
            if (($r = $by->get($m)) !== null) {
                $methods[] = ['method' => CashGuard::LABELS[$m], 'opening_minor' => (int) $r->opening, 'in_minor' => (int) $r->cash_in, 'out_minor' => (int) $r->cash_out, 'closing_minor' => (int) $r->closing];
            }
        }

        return [
            'subtitle' => $this->period($x).($method !== null ? ' · '.CashGuard::LABELS[$method] : ''),
            'columns' => [self::c('date', 'Date', 'date'), self::c('kind', 'Kind'), self::c('description', 'Description'), self::c('method', 'Account'),
                self::c('in_minor', 'In', 'money'), self::c('out_minor', 'Out', 'money'), self::c('balance_minor', 'Balance', 'money')],
            'rows' => $rows,
            'totals' => ['in_minor' => (int) $st['in_minor'], 'out_minor' => (int) $st['out_minor'], 'balance_minor' => (int) $st['closing_minor'],
                'opening_minor' => (int) $st['opening_minor'], 'closing_minor' => (int) $st['closing_minor']],
            'sections' => [['title' => 'By account', 'columns' => [self::c('method', 'Account'), self::c('opening_minor', 'Opening', 'money'), self::c('in_minor', 'In', 'money'),
                self::c('out_minor', 'Out', 'money'), self::c('closing_minor', 'Closing', 'money')], 'rows' => $methods,
                'totals' => ['opening_minor' => array_sum(array_column($methods, 'opening_minor')), 'in_minor' => array_sum(array_column($methods, 'in_minor')),
                    'out_minor' => array_sum(array_column($methods, 'out_minor')), 'closing_minor' => array_sum(array_column($methods, 'closing_minor'))]]],
        ];
    }

    /** @return array<string, mixed> */
    private function tenantStatement(object $x): array
    {
        $lease = $x->lease;
        if ($lease === null) {
            throw BusinessRuleException::make('lease_required', 'Choose the tenancy (lease).');
        }
        $lines = [];
        foreach (RentalCharge::withoutGlobalScopes()->where('company_id', $x->cid)->where('lease_id', $lease->id)->where('status', '!=', 'void')->get() as $c) {
            $lines[] = ['date' => $c->due_date->toDateString(), 'o' => 0, 'id' => (int) $c->id, 'description' => (string) $c->description, 'reference' => null,
                'charged_minor' => (int) $c->amount_minor, 'paid_minor' => 0, 'peek' => 'bill:'.$c->id];
        }
        $tz = LocalDate::timezone($x->cid);
        foreach (RentalPayment::withoutGlobalScopes()->where('company_id', $x->cid)->where('lease_id', $lease->id)->get() as $p) {
            $what = match ($p->method) {
                'waiver' => 'Forgiven'.($p->notes ? ': '.$p->notes : ''),
                'deposit' => 'Paid from the deposit',
                'opening' => (string) ($p->notes ?: 'Brought forward'),
                RentalPayment::DIRECT => 'Paid to the landlord directly'.($p->reference ? ' '.$p->reference : ''),
                default => 'Payment, '.mb_strtolower(self::METHOD_LABELS[$p->method] ?? (string) $p->method).($p->reference ? ' '.$p->reference : ''),
            };
            $lines[] = ['date' => $p->paid_on->toDateString(), 'o' => 1, 'id' => (int) $p->id, 'description' => $what, 'reference' => $p->number,
                'charged_minor' => 0, 'paid_minor' => (int) $p->amount_minor, 'peek' => 'payment:'.$p->id];
            if ($p->reversed_at !== null) {
                $lines[] = ['date' => Carbon::parse($p->reversed_at)->setTimezone($tz)->toDateString(), 'o' => 2, 'id' => (int) $p->id,
                    'description' => 'Payment reversed'.($p->reverse_reason ? ': '.$p->reverse_reason : ''), 'reference' => $p->number,
                    'charged_minor' => (int) $p->amount_minor, 'paid_minor' => 0, 'peek' => 'payment:'.$p->id];
            }
        }
        usort($lines, fn ($a, $b) => [$a['date'], $a['o'], $a['id']] <=> [$b['date'], $b['o'], $b['id']]);
        $opening = 0;
        $run = 0;
        $rows = [];
        foreach ($lines as $l) {
            if ($l['date'] > $x->to) {
                break;
            }
            if ($l['date'] < $x->from) {
                $opening += $l['charged_minor'] - $l['paid_minor'];
                $run = $opening;

                continue;
            }
            $run += $l['charged_minor'] - $l['paid_minor'];
            $rows[] = ['date' => $l['date'], 'description' => $l['description'], 'reference' => $l['reference'],
                'charged_minor' => $l['charged_minor'] ?: null, 'paid_minor' => $l['paid_minor'] ?: null, 'balance_minor' => $run, '_peek' => $l['peek']];
        }
        array_unshift($rows, ['date' => $x->from, 'description' => 'Opening balance', 'reference' => null, 'charged_minor' => null, 'paid_minor' => null, 'balance_minor' => $opening]);
        $tenant = DB::table('rental_tenants')->where('company_id', $x->cid)->where('id', $lease->tenant_id)->first(['name', 'phone']);
        $unit = DB::table('rental_units')->where('company_id', $x->cid)->where('id', $lease->unit_id)->value('code');
        $property = DB::table('rental_properties')->where('company_id', $x->cid)->where('id', $lease->property_id)->value('name');
        $deposit = ['collected_minor' => Deposits::collected($lease), 'used_minor' => Deposits::used($lease), 'refunded_minor' => Deposits::refunded($lease), 'held_minor' => Deposits::held($lease)];

        return [
            'subtitle' => trim(($tenant->name ?? '').' · '.$unit.', '.$property.' · lease '.$lease->number.' · '.$this->period($x)),
            'columns' => [self::c('date', 'Date', 'date'), self::c('description', 'Description'), self::c('reference', 'Receipt'), self::c('charged_minor', 'Billed', 'money'),
                self::c('paid_minor', 'Paid', 'money'), self::c('balance_minor', 'Balance', 'money')],
            'rows' => $rows,
            'totals' => ['charged_minor' => array_sum(array_map(fn ($r) => (int) $r['charged_minor'], $rows)), 'paid_minor' => array_sum(array_map(fn ($r) => (int) $r['paid_minor'], $rows)),
                'balance_minor' => $run, 'opening_minor' => $opening, 'closing_minor' => $run],
            'sections' => [['title' => 'Deposit', 'columns' => [self::c('line', 'Deposit'), self::c('amount_minor', 'Amount', 'money')], 'rows' => [
                ['line' => 'Paid in', 'amount_minor' => $deposit['collected_minor']], ['line' => 'Used for what was owed at move-out', 'amount_minor' => $deposit['used_minor']],
                ['line' => 'Refunded', 'amount_minor' => $deposit['refunded_minor']], ['line' => 'Held now', 'amount_minor' => $deposit['held_minor']]], 'totals' => []]],
            'lease' => ['id' => (int) $lease->id, 'number' => (string) $lease->number, 'tenant' => $tenant->name ?? null, 'unit' => $unit, 'property' => $property],
        ];
    }

    /** @return array<string, mixed> */
    private function leaseExpiries(object $x): array
    {
        $from = $x->asOf->toDateString();
        $days = max(1, min(366, (int) ($x->params['days'] ?? 60)));
        $to = $x->asOf->copy()->addDays($days)->toDateString();
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $x->cid)->whereIn('status', RentalLease::LIVE)
            ->when($x->ids !== null, fn ($q) => $q->whereIn('property_id', $x->ids ?: [0]))
            ->where(fn ($q) => $q->whereBetween('end_date', [$from, $to])->orWhere(fn ($q) => $q->where('status', 'notice')->whereBetween('move_out_on', [$from, $to])))->get();
        $tenants = DB::table('rental_tenants')->where('company_id', $x->cid)->whereIn('id', $leases->pluck('tenant_id')->all() ?: [0])->get(['id', 'name', 'phone'])->keyBy('id');
        $units = $this->names('rental_units', 'code', $leases->pluck('unit_id')->all(), $x->cid);
        $props = $this->names('rental_properties', 'name', $leases->pluck('property_id')->all(), $x->cid);
        $positions = Leases::positions($leases->all(), $x->today);
        $rows = [];
        foreach ($leases as $l) {
            $moving = $l->status === 'notice' && $l->move_out_on !== null && $l->move_out_on->toDateString() >= $from && $l->move_out_on->toDateString() <= $to;
            $ends = $moving ? $l->move_out_on : $l->end_date;
            if ($moving && $l->end_date !== null && $l->end_date->lt($l->move_out_on) && $l->end_date->toDateString() >= $from) {
                $ends = $l->end_date;
                $moving = false;
            }
            $pos = $positions[(int) $l->id];
            $t = $tenants->get($l->tenant_id);
            $rows[] = ['ends_on' => $ends->toDateString(), 'why' => $moving ? 'Moving out (notice given'.($l->notice_on ? ' '.$l->notice_on->format('j M Y') : '').')' : 'Fixed term ends',
                'days_left' => max(0, Periods::days($x->asOf, $ends)), 'lease' => $l->number, 'tenant' => $t?->name, 'phone' => $t?->phone, 'unit' => $units[$l->unit_id] ?? '',
                'property' => $props[$l->property_id] ?? '', 'rent_minor' => (int) $l->rent_minor, 'owed_minor' => $pos['owed_minor'], 'deposit_held_minor' => $pos['deposit_held_minor'], '_peek' => 'lease:'.$l->id];
        }
        usort($rows, fn ($a, $b) => [$a['ends_on'], $a['property'], $a['unit']] <=> [$b['ends_on'], $b['property'], $b['unit']]);

        return [
            'subtitle' => 'Ending '.$x->asOf->format('j M Y').' to '.Carbon::parse($to)->format('j M Y').' (the next '.$days.' days)'.$this->filterWords($x),
            'columns' => [self::c('ends_on', 'Ends', 'date'), self::c('why', 'Why'), self::c('days_left', 'Days left', 'int'), self::c('lease', 'Lease'), self::c('tenant', 'Tenant'),
                self::c('phone', 'Phone'), self::c('unit', 'Unit'), self::c('property', 'Property'), self::c('rent_minor', 'Rent', 'money'), self::c('owed_minor', 'Owed', 'money'),
                self::c('deposit_held_minor', 'Deposit held', 'money')],
            'rows' => $rows,
            'totals' => ['rent_minor' => array_sum(array_column($rows, 'rent_minor')), 'owed_minor' => array_sum(array_column($rows, 'owed_minor')),
                'deposit_held_minor' => array_sum(array_column($rows, 'deposit_held_minor')), 'leases' => count($rows)],
            'from' => $from, 'to' => $to,
        ];
    }

    /** @return array<string, mixed> */
    private function maintenance(object $x): array
    {
        $tz = LocalDate::timezone($x->cid);
        $start = Carbon::parse($x->from, $tz)->startOfDay()->utc();
        $end = Carbon::parse($x->to, $tz)->endOfDay()->utc();
        $list = RentalMaintenance::withoutGlobalScopes()->where('company_id', $x->cid)
            ->when($x->ids !== null, fn ($q) => $q->whereIn('property_id', $x->ids ?: [0]))
            ->where(fn ($q) => $q->whereBetween('created_at', [$start, $end])->orWhere(fn ($q) => $q->whereIn('status', ['open', 'in_progress'])->where('created_at', '<=', $end)))
            ->orderBy('id')->get();
        $costs = DB::table('rental_entries')->where('company_id', $x->cid)->whereIn('expense_id', $list->pluck('expense_id')->filter()->all() ?: [0])
            ->groupBy('expense_id')->selectRaw('expense_id, -SUM(cash_minor) AS cost')->pluck('cost', 'expense_id')->all();
        $props = $this->names('rental_properties', 'name', $list->pluck('property_id')->all(), $x->cid);
        $units = $this->names('rental_units', 'code', $list->pluck('unit_id')->all(), $x->cid);
        $rows = [];
        $byStatus = [];
        foreach ($list as $m) {
            $cost = $m->expense_id !== null ? (int) ($costs[$m->expense_id] ?? 0) : 0;
            $status = Maintenance::STATUSES[$m->status] ?? ucfirst((string) $m->status);
            $rows[] = ['number' => $m->number, 'opened' => Carbon::parse($m->created_at)->setTimezone($tz)->toDateString(), 'property' => $props[$m->property_id] ?? '',
                'unit' => $units[$m->unit_id] ?? null, 'title' => $m->title, 'priority' => Maintenance::PRIORITIES[$m->priority] ?? ucfirst((string) $m->priority),
                'status' => $status, 'reported_by' => ucfirst((string) $m->reported_by), 'assignee' => $m->assignee_name, 'resolved_on' => $m->resolved_on?->toDateString(),
                'cost_minor' => $cost, '_peek' => 'ticket:'.$m->id];
            $g = $byStatus[$m->status] ?? ['status' => $status, 'requests' => 0, 'cost_minor' => 0];
            $g['requests']++;
            $g['cost_minor'] += $cost;
            $byStatus[$m->status] = $g;
        }
        $statuses = array_values(array_intersect_key(array_replace(array_fill_keys(array_keys(Maintenance::STATUSES), null), $byStatus), $byStatus));

        return [
            'columns' => [self::c('number', 'Number'), self::c('opened', 'Opened', 'date'), self::c('property', 'Property'), self::c('unit', 'Unit'), self::c('title', 'Problem'),
                self::c('priority', 'Priority'), self::c('status', 'Status'), self::c('reported_by', 'Reported by'), self::c('assignee', 'Fixed by'),
                self::c('resolved_on', 'Done on', 'date'), self::c('cost_minor', 'Cost', 'money')],
            'rows' => $rows,
            'totals' => ['cost_minor' => array_sum(array_column($rows, 'cost_minor')), 'requests' => count($rows),
                'still_open' => count(array_filter($list->all(), fn ($m) => in_array($m->status, ['open', 'in_progress'], true)))],
            'sections' => [['title' => 'By status', 'columns' => [self::c('status', 'Status'), self::c('requests', 'Requests', 'int'), self::c('cost_minor', 'Cost', 'money')],
                'rows' => $statuses, 'totals' => ['requests' => array_sum(array_column($statuses, 'requests')), 'cost_minor' => array_sum(array_column($statuses, 'cost_minor'))]]],
        ];
    }

    /** @return array<string, mixed> */
    private function incomeStatement(object $x): array
    {
        $allocs = DB::table('rental_allocations as a')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')
            ->leftJoin('rental_lease_items as i', 'i.id', '=', 'c.item_id')
            ->where('a.company_id', $x->cid)->whereBetween('a.applied_on', [$x->from, $x->to])->where('p.method', '!=', 'waiver')->where('c.kind', '!=', 'refund')
            ->groupBy('c.kind', 'i.name', 'c.landlord_id')
            ->selectRaw('c.kind, i.name AS item, c.landlord_id, SUM(a.commission_minor) AS commission, SUM(a.agency_minor) AS agency, SUM(a.landlord_minor) AS landlord')->get();
        $expenseRows = DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')->leftJoin('rental_expenses as x', 'x.id', '=', 'e.expense_id')
            ->where('e.company_id', $x->cid)->whereBetween('e.occurred_on', [$x->from, $x->to])
            ->where(fn ($q) => $q->where('e.type', $x->owner ? 'expense_landlord' : 'expense_agency')
                ->orWhere(fn ($q) => $q->where('e.type', 'reversal')->where('o.type', $x->owner ? 'expense_landlord' : 'expense_agency')))
            ->groupBy('x.category')->selectRaw('x.category, SUM(-e.cash_minor) AS amount')->get();
        $kind = fn (object $a) => match ($a->kind) {
            'rent' => 'Rent', 'item' => (string) ($a->item ?: 'Fees'), 'utility' => 'Water and power', 'late_fee' => 'Late fees', 'opening' => 'Balances brought forward',
            'deposit' => 'Deposits', default => 'Other charges',
        };
        $rows = [];
        $income = [];
        if ($x->owner) {
            foreach ($allocs as $a) {
                if ($a->kind === 'deposit') {
                    continue; // a deposit is the tenant's money, never income (KR-13)
                }
                $income[$kind($a)] = ($income[$kind($a)] ?? 0) + (int) $a->landlord + (int) $a->commission + (int) $a->agency;
            }
        } else {
            $landlords = $this->names('rental_landlords', 'name', $allocs->pluck('landlord_id')->all(), $x->cid);
            foreach ($allocs as $a) {
                if ((int) $a->commission !== 0) {
                    $k = 'Commission: '.($landlords[$a->landlord_id] ?? 'landlord');
                    $income[$k] = ($income[$k] ?? 0) + (int) $a->commission;
                }
                if ((int) $a->agency !== 0) {
                    $k = 'Fees kept: '.$kind($a);
                    $income[$k] = ($income[$k] ?? 0) + (int) $a->agency;
                }
            }
        }
        ksort($income);
        foreach ($income as $line => $amount) {
            if ($amount !== 0) {
                $rows[] = ['section' => 'Income', 'line' => $line, 'amount_minor' => $amount];
            }
        }
        $expenses = 0;
        foreach ($expenseRows->sortBy('category') as $e) {
            if ((int) $e->amount !== 0) {
                $rows[] = ['section' => 'Expenses', 'line' => (string) ($e->category ?: 'Other'), 'amount_minor' => -(int) $e->amount];
                $expenses += (int) $e->amount;
            }
        }
        $incomeTotal = array_sum($income);
        $totals = ['amount_minor' => $incomeTotal - $expenses, 'income_minor' => $incomeTotal, 'expenses_minor' => $expenses, 'net_minor' => $incomeTotal - $expenses];
        if ($x->owner) {
            $totals['drawings_minor'] = -(int) DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')->where('e.company_id', $x->cid)
                ->whereBetween('e.occurred_on', [$x->from, $x->to])->where(fn ($q) => $q->where('e.type', 'payout')->orWhere(fn ($q) => $q->where('e.type', 'reversal')->where('o.type', 'payout')))
                ->sum('e.landlord_minor');
        }

        return [
            'columns' => [self::c('section', 'Section'), self::c('line', 'Line'), self::c('amount_minor', 'Amount', 'money')],
            'rows' => $rows, 'totals' => $totals, 'sections' => $x->owner ? [] : $this->takenOut($x),
        ];
    }

    /**
     * The line under an agency's income statement: what it took out of its own money in the period. Never part of its income, and left
     * out when there is none.
     *
     * @return list<array<string, mixed>>
     */
    private function takenOut(object $x): array
    {
        $taken = -(int) DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')->where('e.company_id', $x->cid)
            ->whereBetween('e.occurred_on', [$x->from, $x->to])->where(fn ($q) => $q->where('e.type', 'agency_drawing')->orWhere(fn ($q) => $q->where('e.type', 'reversal')->where('o.type', 'agency_drawing')))
            ->sum('e.equity_minor');
        if ($taken === 0) {
            return [];
        }

        return [['title' => self::ENTRY_LABELS['agency_drawing'], 'columns' => [self::c('line', 'Line'), self::c('amount_minor', 'Amount', 'money')],
            'rows' => [['line' => 'The agency\'s own money, taken out in the period. It is not an expense.', 'amount_minor' => $taken]], 'totals' => []]];
    }

    /**
     * The one-page monthly building report (the benchmark's face, EL-04): what came in by kind, the expenses and the
     * final balance for one calendar month, with deposits and money paid ahead kept apart because they are the tenants'
     * money, never income. By business date, so the whole of the last day is in. Reversals count in the month they were
     * made. `figures` holds the same numbers by name.
     *
     * @return array<string, mixed>
     */
    private function monthlyBuilding(object $x): array
    {
        $allocations = fn () => DB::table('rental_allocations as a')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.company_id', $x->cid)->where('p.method', '!=', 'waiver')
            ->when($x->ids !== null, fn ($q) => $q->whereIn('c.property_id', $x->ids ?: [0]));

        // Income: what settled a bill in the month, whoever's share it is. Money a tenant paid the landlord directly never came in, though what the agency earns on it did (LC-20).
        $bills = $allocations()->leftJoin('rental_lease_items as i', 'i.id', '=', 'c.item_id')->whereBetween('a.applied_on', [$x->from, $x->to])
            ->whereNotIn('c.kind', ['deposit', 'refund'])->groupBy('c.kind', 'i.name', 'c.description', 'c.period_start', 'c.period_end')
            ->selectRaw('c.kind, i.name AS item, c.description, c.period_start, c.period_end, SUM(CASE WHEN p.method = ? THEN 0 ELSE a.amount_minor END) AS amount, SUM(a.commission_minor) AS commission, SUM(a.agency_minor) AS agency, SUM(CASE WHEN p.method = ? THEN a.amount_minor ELSE 0 END) AS direct', [RentalPayment::DIRECT, RentalPayment::DIRECT])->get();
        $direct = 0;
        $income = [];
        $commission = 0;
        $kept = 0;
        foreach ($bills as $b) {
            $line = match ($b->kind) {
                'rent' => self::isPartMonth($b) ? 'Part-month rent' : 'Rent',
                'item' => (string) ($b->item ?: 'Other fees'),
                'utility' => 'Water and power', 'late_fee' => 'Late fees', 'opening' => 'Balances brought forward',
                default => self::isPartMonth($b) ? 'Part-month rent' : 'Other charges',
            };
            $income[$line] = ($income[$line] ?? 0) + (int) $b->amount;
            $commission += (int) $b->commission;
            $kept += (int) $b->agency;
            $direct += (int) $b->direct;
        }
        $first = array_intersect_key(['Rent' => 0, 'Part-month rent' => 0], $income);
        $rest = array_diff_key($income, $first);
        ksort($rest);
        $rows = [];
        foreach (array_replace($first, $income, $rest) as $line => $amount) {
            if ($amount !== 0) {
                $rows[] = ['line' => $line, 'amount_minor' => $amount];
            }
        }
        $incomeTotal = array_sum($income);

        // Expenses paid for the buildings, the ones the agency keeps as its own costs left out.
        $list = DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')->leftJoin('rental_expenses as r', 'r.id', '=', 'e.expense_id')
            ->where('e.company_id', $x->cid)->whereBetween('e.occurred_on', [$x->from, $x->to])
            ->where(fn ($q) => $q->where('e.type', 'expense_landlord')->orWhere(fn ($q) => $q->where('e.type', 'reversal')->where('o.type', 'expense_landlord')))
            ->when($x->ids !== null, fn ($q) => $q->whereIn('e.property_id', $x->ids ?: [0]))
            ->orderBy('e.occurred_on')->orderBy('e.id')->get(['e.occurred_on', 'e.type', 'e.cash_minor', 'r.category', 'r.description']);
        $expenses = [];
        $byCategory = [];
        foreach ($list as $e) {
            $amount = -(int) $e->cash_minor;
            $category = (string) ($e->category ?: 'Other');
            $expenses[] = ['date' => substr((string) $e->occurred_on, 0, 10), 'category' => $category,
                'description' => ($e->type === 'reversal' ? 'Reversed: ' : '').$e->description, 'amount_minor' => $amount];
            $g = $byCategory[$category] ?? ['category' => $category, 'count' => 0, 'amount_minor' => 0];
            $g['count'] += $e->type === 'reversal' ? 0 : 1;
            $g['amount_minor'] += $amount;
            $byCategory[$category] = $g;
        }
        ksort($byCategory);
        $expenseTotal = array_sum(array_column($expenses, 'amount_minor'));

        // Deposits and money paid ahead: the tenants' money, shown apart and never added to the balance.
        $depositIn = fn (callable $period, array $methods, bool $not) => (int) $period($allocations()->where('c.kind', 'deposit'))
            ->when($methods !== [], fn ($q) => $not ? $q->whereNotIn('p.method', $methods) : $q->whereIn('p.method', $methods))->sum('a.amount_minor');
        $inMonth = fn ($q) => $q->whereBetween('a.applied_on', [$x->from, $x->to]);
        $toDate = fn ($q) => $q->where('a.applied_on', '<=', $x->to);
        $received = $depositIn($inMonth, ['opening'], true);
        $brought = $depositIn($inMonth, ['opening'], false);
        $used = (int) $inMonth($allocations()->where('p.method', 'deposit'))->sum('a.amount_minor');
        $refunds = fn (callable $period) => (int) -$period(DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')->where('e.company_id', $x->cid)
            ->where(fn ($q) => $q->whereIn('e.type', Deposits::REFUND_TYPES)->orWhere(fn ($q) => $q->where('e.type', 'reversal')->whereIn('o.type', Deposits::REFUND_TYPES)))
            ->when($x->ids !== null, fn ($q) => $q->whereIn('e.property_id', $x->ids ?: [0])))->sum('e.cash_minor');
        $refunded = $refunds(fn ($q) => $q->whereBetween('e.occurred_on', [$x->from, $x->to]));
        $held = max(0, $depositIn($toDate, [], false) - (int) $toDate($allocations()->where('p.method', 'deposit'))->sum('a.amount_minor')
            - $refunds(fn ($q) => $q->where('e.occurred_on', '<=', $x->to)));
        $paidAhead = (int) DB::table('rental_entries')->where('company_id', $x->cid)->whereBetween('occurred_on', [$x->from, $x->to])->where('type', '!=', 'opening_credit')
            ->when($x->ids !== null, fn ($q) => $q->whereIn('property_id', $x->ids ?: [0]))->sum('credit_minor');

        $money = fn (string $line, int $amount) => ['line' => $line, 'amount_minor' => $amount];
        $deposits = [$money('Deposits received', $received)];
        if ($brought !== 0) {
            $deposits[] = $money('Deposits brought forward', $brought);
        }
        array_push($deposits, $money('Deposits used for what was owed', $used), $money('Deposits refunded', $refunded), $money('Deposits held at the end of the month', $held),
            $money('Paid ahead by tenants, less what was used', $paidAhead));
        $balance = [$money('Total income', $incomeTotal)];
        if (! $x->owner && $commission !== 0) {
            $balance[] = $money('Less commission', -$commission);
        }
        if (! $x->owner && $kept !== 0) {
            $balance[] = $money('Less fees the agency keeps', -$kept);
        }
        $balance[] = $money('Less expenses', -$expenseTotal);
        $final = $incomeTotal - ($x->owner ? 0 : $commission + $kept) - $expenseTotal;
        $amount = self::c('amount_minor', 'Amount', 'money');

        // Beside the books: what went out to landlords or the owner, how full the buildings are, and who is overdue today.
        // Whole-account figures a caretaker does not see (a caretaker's report keeps to their buildings).
        $paidOut = null;
        if (RentalAccess::propertyIds($x->by) === null) {
            $landlords = $x->ids === null ? null : DB::table('rental_properties')->where('company_id', $x->cid)->whereIn('id', $x->ids ?: [0])->pluck('landlord_id')->filter()->all();
            $paidOut = (int) -DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')->where('e.company_id', $x->cid)
                ->whereBetween('e.occurred_on', [$x->from, $x->to])->where(fn ($q) => $q->where('e.type', 'payout')->orWhere(fn ($q) => $q->where('e.type', 'reversal')->where('o.type', 'payout')))
                ->when($landlords !== null, fn ($q) => $q->whereIn('e.landlord_id', $landlords ?: [0]))->sum('e.landlord_minor');
        }
        $occupancy = $this->occupancy($x);
        $occupiedOn = min($x->to, $x->today->toDateString());
        $overdue = Arrears::byLease($x->cid, $x->by, null, $x->today);
        $overdueLeases = DB::table('rental_leases')->where('company_id', $x->cid)->whereIn('id', array_keys($overdue) ?: [0])
            ->when($x->ids !== null, fn ($q) => $q->whereIn('property_id', $x->ids ?: [0]))->get(['id', 'tenant_id']);
        $overdueMinor = array_sum(array_map(fn ($l) => (int) $overdue[$l->id]['total_minor'], $overdueLeases->all()));
        $overdueTenants = $overdueLeases->pluck('tenant_id')->unique()->count();
        $paidOutLabel = $x->owner ? 'Owner drawings' : ($x->ids === null ? 'Paid to landlords' : 'Paid to the landlord (all their buildings)');
        $also = [];
        if ($paidOut !== null) {
            $also[] = ['line' => $paidOutLabel, 'count' => null, 'amount_minor' => $paidOut];
        }
        if ($direct !== 0) {
            $also[] = ['line' => 'Tenants paid landlords directly (not income above)', 'count' => null, 'amount_minor' => $direct];
        }
        array_push($also, ['line' => 'Units occupied on '.Carbon::parse($occupiedOn)->format('j M Y'), 'count' => (int) $occupancy['totals']['occupied'], 'amount_minor' => null],
            ['line' => 'Units in all', 'count' => (int) $occupancy['totals']['units'], 'amount_minor' => null],
            ['line' => 'Tenants overdue on '.$x->today->format('j M Y'), 'count' => $overdueTenants, 'amount_minor' => $overdueMinor]);
        $balanceFor = $x->owner ? 'Balance for the owner' : ($x->ids === null ? 'Balance for the landlords' : 'Balance for the landlord');

        return [
            'subtitle' => Carbon::parse($x->from)->format('F Y').' · '.($x->property !== null ? $x->property->name : 'All properties').' · '.Carbon::parse($x->from)->format('j M Y').' to '.Carbon::parse($x->to)->format('j M Y'),
            'columns' => [self::c('line', 'Income'), $amount],
            'rows' => $rows,
            'totals' => ['amount_minor' => $incomeTotal],
            'total_label' => 'Total income',
            'one_page' => true,
            'sections' => [
                ['title' => 'Expenses', 'columns' => [self::c('date', 'Date', 'date'), self::c('category', 'Category'), self::c('description', 'Description'), $amount],
                    'rows' => $expenses, 'totals' => ['amount_minor' => $expenseTotal], 'total_label' => 'Total expenses',
                    'summary' => ['title' => 'Expenses by category (the Excel and CSV files list each one)', 'columns' => [self::c('category', 'Category'), self::c('count', 'Expenses', 'int'), $amount],
                        'rows' => array_values($byCategory)]],
                ['title' => 'Final balance', 'columns' => [self::c('line', 'Worked out as'), $amount], 'rows' => $balance, 'totals' => ['amount_minor' => $final], 'total_label' => $balanceFor],
                ['title' => 'Deposits and money paid ahead (not income)', 'columns' => [self::c('line', 'The tenants\' money'), $amount], 'rows' => $deposits, 'totals' => []],
                ['title' => 'Paid out, occupancy and arrears', 'columns' => [self::c('line', 'What'), self::c('count', 'How many', 'int'), $amount], 'rows' => $also, 'totals' => []],
            ],
            'figures' => ['income_minor' => $incomeTotal, 'expenses_minor' => $expenseTotal, 'commission_minor' => $x->owner ? 0 : $commission, 'fees_kept_minor' => $x->owner ? 0 : $kept,
                'final_balance_minor' => $final, 'deposits_received_minor' => $received, 'deposits_brought_minor' => $brought, 'deposits_used_minor' => $used,
                'deposits_refunded_minor' => $refunded, 'deposits_held_minor' => $held, 'paid_ahead_minor' => $paidAhead,
                'units' => (int) $occupancy['totals']['units'], 'occupied' => (int) $occupancy['totals']['occupied'], 'occupied_on' => $occupiedOn,
                'overdue_minor' => $overdueMinor, 'overdue_tenants' => $overdueTenants, 'as_at' => $x->today->toDateString(), 'direct_minor' => $direct]
                + ($paidOut !== null ? ['paid_out_minor' => $paidOut, 'paid_out_label' => $paidOutLabel] : []),
        ];
    }

    /** A bill for less than a whole month from its first day: a part month (a move-in or move-out), or one typed as such. */
    private static function isPartMonth(object $bill): bool
    {
        if (preg_match('/part[- ]month/i', (string) $bill->description)) {
            return true;
        }
        if ($bill->kind !== 'rent' || $bill->period_start === null || $bill->period_end === null) {
            return false;
        }

        return Carbon::parse((string) $bill->period_end)->lt(Carbon::parse((string) $bill->period_start)->addMonthNoOverflow()->subDay());
    }

    /** Each tenancy with a deposit: received, used, refunded and held today, in one read per column rather than per lease. @return array<string, mixed> */
    private function depositRegister(object $x): array
    {
        $bills = RentalCharge::withoutGlobalScopes()->where('company_id', $x->cid)->where('kind', 'deposit')->select('lease_id');
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $x->cid)->where('status', '!=', 'cancelled')->whereIn('id', $bills)
            ->when($x->ids !== null, fn ($q) => $q->whereIn('property_id', $x->ids ?: [0]))->orderBy('id')->get();
        $ids = $leases->pluck('id')->all() ?: [0];
        $received = DB::table('rental_allocations as a')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.company_id', $x->cid)->where('c.kind', 'deposit')->where('p.method', '!=', 'waiver')->whereIn('c.lease_id', $ids)->groupBy('c.lease_id')
            ->selectRaw('c.lease_id, SUM(a.amount_minor) AS total')->pluck('total', 'lease_id')->all();
        $used = DB::table('rental_payments')->where('company_id', $x->cid)->where('method', 'deposit')->whereNull('reversed_at')->whereIn('lease_id', $ids)->groupBy('lease_id')
            ->selectRaw('lease_id, SUM(amount_minor) AS total')->pluck('total', 'lease_id')->all();
        $refunded = DB::table('rental_entries')->where('company_id', $x->cid)->whereIn('type', Deposits::REFUND_TYPES)->whereNull('reversed_at')->whereIn('lease_id', $ids)->groupBy('lease_id')
            ->selectRaw('lease_id, -SUM(cash_minor) AS total')->pluck('total', 'lease_id')->all();
        $tenants = $this->names('rental_tenants', 'name', $leases->pluck('tenant_id')->all(), $x->cid);
        $units = $this->names('rental_units', 'code', $leases->pluck('unit_id')->all(), $x->cid);
        $props = $this->names('rental_properties', 'name', $leases->pluck('property_id')->all(), $x->cid);
        $landlords = $this->names('rental_landlords', 'name', $leases->pluck('landlord_id')->all(), $x->cid);
        $rows = [];
        foreach ($leases as $l) {
            $in = (int) ($received[$l->id] ?? 0);
            $use = (int) ($used[$l->id] ?? 0);
            $back = (int) ($refunded[$l->id] ?? 0);
            $rows[] = ['tenant' => $tenants[$l->tenant_id] ?? '', 'unit' => $units[$l->unit_id] ?? '', 'property' => $props[$l->property_id] ?? '', 'landlord' => $landlords[$l->landlord_id] ?? '',
                'lease' => $l->number, 'status' => $l->isLive() ? ($l->status === 'notice' ? 'Under notice' : 'Staying') : 'Moved out',
                'held_by' => $l->deposit_held_by === 'landlord' ? 'Landlord' : ($x->owner ? 'You' : 'Agency'), 'agreed_minor' => (int) $l->deposit_minor, 'received_minor' => $in,
                'used_minor' => $use, 'refunded_minor' => $back, 'held_minor' => max(0, $in - $use - $back), '_peek' => 'lease:'.$l->id];
        }
        usort($rows, fn ($a, $b) => [$a['property'], 0] <=> [$b['property'], 0] ?: strnatcasecmp($a['unit'], $b['unit']) ?: strcasecmp($a['tenant'], $b['tenant']));
        $sum = fn (string $k) => array_sum(array_column($rows, $k));

        return [
            'subtitle' => $this->asAt($x),
            'columns' => [self::c('tenant', 'Tenant'), self::c('unit', 'Unit'), self::c('property', 'Property'), self::c('landlord', 'Landlord'), self::c('lease', 'Lease'), self::c('status', 'Tenancy'),
                self::c('held_by', 'Held by'), self::c('agreed_minor', 'Agreed', 'money'), self::c('received_minor', 'Received', 'money'), self::c('used_minor', 'Used', 'money'),
                self::c('refunded_minor', 'Refunded', 'money'), self::c('held_minor', 'Held', 'money')],
            'rows' => $rows,
            'totals' => ['agreed_minor' => $sum('agreed_minor'), 'received_minor' => $sum('received_minor'), 'used_minor' => $sum('used_minor'), 'refunded_minor' => $sum('refunded_minor'),
                'held_minor' => $sum('held_minor'), 'leases' => count($rows)],
        ];
    }

    // ── Context, filters and words ───────────────────────────────────────

    /** @return array<string, mixed> */
    private function tenantList(object $x): array
    {
        $cut = $x->today->copy()->subDays((int) RentalsModule::settings($x->cid)->grace_days)->toDateString();
        $q = DB::table('rental_tenants as t')->where('t.company_id', $x->cid)
            ->when($x->ids !== null, fn ($w) => $w->whereExists(fn ($e) => $e->from('rental_leases as l')->whereColumn('l.tenant_id', 't.id')->where('l.company_id', $x->cid)->whereIn('l.property_id', $x->ids ?: [0])));
        match ((string) ($x->params['status'] ?? 'all')) {
            'current' => $q->where('t.status', 'active'),
            'former' => $q->where('t.status', 'former'),
            'owing' => $q->whereExists(fn ($e) => $e->from('rental_charges as c')->whereColumn('c.tenant_id', 't.id')->where('c.company_id', $x->cid)->where('c.status', 'open')
                ->where('c.kind', '!=', 'refund')->where('c.due_date', '<', $cut)),
            default => null,
        };
        $tenants = $q->orderBy('t.name')->get(['t.id', 't.code', 't.name', 't.phone', 't.phone_e164', 't.status'])
            ->filter(fn ($t) => self::matches($x, [(string) $t->name, (string) $t->code, (string) $t->phone, (string) $t->phone_e164]))->values();
        $ids = $tenants->pluck('id')->all() ?: [0];
        $units = DB::table('rental_leases as l')->join('rental_units as u', 'u.id', '=', 'l.unit_id')->where('l.company_id', $x->cid)->whereIn('l.tenant_id', $ids)->whereIn('l.status', RentalLease::LIVE)
            ->when($x->ids !== null, fn ($w) => $w->whereIn('l.property_id', $x->ids ?: [0]))->get(['l.tenant_id', 'u.code'])->groupBy('tenant_id');
        $owed = DB::table('rental_charges')->where('company_id', $x->cid)->whereIn('tenant_id', $ids)->where('status', 'open')->where('kind', '!=', 'refund')
            ->when($x->ids !== null, fn ($w) => $w->whereIn('property_id', $x->ids ?: [0]))->groupBy('tenant_id')
            ->selectRaw('tenant_id, SUM(amount_minor - paid_minor) AS owed, SUM(CASE WHEN due_date < ? THEN amount_minor - paid_minor ELSE 0 END) AS overdue', [$cut])->get()->keyBy('tenant_id');
        $rows = [];
        foreach ($tenants as $t) {
            $o = $owed->get($t->id);
            $rows[] = ['code' => $t->code, 'name' => $t->name, 'phone' => $t->phone, 'renting' => ($units->get($t->id) ?? collect())->pluck('code')->implode(', '),
                'status' => $t->status === 'active' ? 'Current' : 'Former', 'owed_minor' => (int) ($o->owed ?? 0), 'overdue_minor' => (int) ($o->overdue ?? 0), '_peek' => 'tenant:'.$t->id];
        }

        return [
            'subtitle' => $this->asAt($x),
            'columns' => [self::c('code', 'Tenant number'), self::c('name', 'Tenant'), self::c('phone', 'Phone'), self::c('renting', 'Renting'), self::c('status', 'Status'),
                self::c('owed_minor', 'Owed', 'money'), self::c('overdue_minor', 'Overdue', 'money')],
            'rows' => $rows,
            'totals' => ['owed_minor' => array_sum(array_column($rows, 'owed_minor')), 'overdue_minor' => array_sum(array_column($rows, 'overdue_minor')), 'tenants' => count($rows)],
        ];
    }

    /** @return array<string, mixed> */
    private function leaseList(object $x): array
    {
        $today = $x->today->toDateString();
        $soon = $x->today->copy()->addDays(30)->toDateString();
        $q = RentalLease::withoutGlobalScopes()->where('rental_leases.company_id', $x->cid)->when($x->ids !== null, fn ($w) => $w->whereIn('rental_leases.property_id', $x->ids ?: [0]))
            ->join('rental_tenants as t', 't.id', '=', 'rental_leases.tenant_id')->join('rental_units as u', 'u.id', '=', 'rental_leases.unit_id')
            ->join('rental_properties as p', 'p.id', '=', 'rental_leases.property_id')
            ->select('rental_leases.*', 't.name as tenant_name', 't.phone as tenant_phone', 'u.code as unit_code', 'p.name as property_name');
        match ((string) ($x->params['status'] ?? 'all')) {
            'live' => $q->whereIn('rental_leases.status', RentalLease::LIVE),
            'notice' => $q->where('rental_leases.status', 'notice'),
            'term' => $q->whereIn('rental_leases.status', RentalLease::LIVE)->whereNotNull('rental_leases.end_date')->where('rental_leases.end_date', '<', $today),
            'ending' => $q->whereIn('rental_leases.status', RentalLease::LIVE)->where(fn ($w) => $w->whereBetween('rental_leases.end_date', [$today, $soon])
                ->orWhere(fn ($n) => $n->where('rental_leases.status', 'notice')->whereBetween('rental_leases.move_out_on', [$today, $soon]))),
            'ended' => $q->where('rental_leases.status', 'ended'),
            'cancelled' => $q->where('rental_leases.status', 'cancelled'),
            default => null,
        };
        $leases = $q->orderBy('p.name')->orderByRaw('LENGTH(u.code), u.code')->orderByDesc('rental_leases.start_date')->get()
            ->filter(fn ($l) => self::matches($x, [(string) $l->number, (string) $l->tenant_name, (string) $l->unit_code, (string) $l->property_name]))->values();
        $ids = $leases->pluck('id')->map(fn ($v) => (int) $v)->all() ?: [0];
        $cut = $x->today->copy()->subDays((int) RentalsModule::settings($x->cid)->grace_days)->toDateString();
        $owed = DB::table('rental_charges')->where('company_id', $x->cid)->whereIn('lease_id', $ids)->where('status', 'open')->where('kind', '!=', 'refund')->groupBy('lease_id')
            ->selectRaw('lease_id, SUM(amount_minor - paid_minor) AS owed, SUM(CASE WHEN due_date < ? THEN amount_minor - paid_minor ELSE 0 END) AS overdue', [$cut])->get()->keyBy('lease_id');
        $paid = Leases::paidUpToMany($leases);
        $words = ['active' => 'Current', 'notice' => 'Under notice', 'ended' => 'Ended', 'cancelled' => 'Cancelled'];
        $rows = [];
        $rent = 0;
        foreach ($leases as $l) {
            $o = $owed->get($l->id);
            $rent += $l->isLive() ? (int) $l->rent_minor : 0; // what the live leases bring in; an ended lease's rent is history
            $rows[] = ['number' => $l->number, 'tenant' => $l->tenant_name, 'phone' => $l->tenant_phone, 'unit' => $l->unit_code, 'property' => $l->property_name,
                'start' => $l->start_date->toDateString(), 'end' => ($l->ended_on ?? $l->end_date)?->toDateString(), 'status' => $words[$l->status] ?? ucfirst((string) $l->status),
                'cycle_months' => (int) $l->cycle_months, 'rent_minor' => (int) $l->rent_minor, 'owed_minor' => (int) ($o->owed ?? 0), 'overdue_minor' => (int) ($o->overdue ?? 0),
                'paid_up_to' => $paid[(int) $l->id] ?? null, '_peek' => 'lease:'.$l->id];
        }

        return [
            'subtitle' => $this->asAt($x),
            'columns' => [self::c('number', 'Lease'), self::c('tenant', 'Tenant'), self::c('phone', 'Phone'), self::c('unit', 'Unit'), self::c('property', 'Property'), self::c('start', 'From', 'date'),
                self::c('end', 'To', 'date'), self::c('status', 'Status'), self::c('cycle_months', 'Months per bill', 'int'), self::c('rent_minor', 'Rent', 'money'), self::c('owed_minor', 'Owed', 'money'),
                self::c('overdue_minor', 'Overdue', 'money'), self::c('paid_up_to', 'Paid up to', 'date')],
            'rows' => $rows,
            'totals' => ['rent_minor' => $rent, 'owed_minor' => array_sum(array_column($rows, 'owed_minor')), 'overdue_minor' => array_sum(array_column($rows, 'overdue_minor')), 'leases' => count($rows)],
        ];
    }

    /** @return array<string, mixed> */
    private function receipts(object $x): array
    {
        $q = DB::table('rental_payments as r')->join('rental_tenants as t', 't.id', '=', 'r.tenant_id')->join('rental_units as u', 'u.id', '=', 'r.unit_id')
            ->join('rental_properties as p', 'p.id', '=', 'r.property_id')->join('rental_leases as l', 'l.id', '=', 'r.lease_id')
            ->where('r.company_id', $x->cid)->whereBetween('r.paid_on', [$x->from, $x->to])->when($x->ids !== null, fn ($w) => $w->whereIn('r.property_id', $x->ids ?: [0]));
        if (in_array($x->params['method'] ?? null, RentalPayment::METHODS, true)) {
            $q->where('r.method', $x->params['method']);
        }
        match ((string) ($x->params['view'] ?? 'all')) {
            'money' => $q->whereIn('r.method', RentalPayment::CASH_METHODS)->whereNull('r.reversed_at'),
            'other' => $q->whereNotIn('r.method', RentalPayment::CASH_METHODS),
            'reversed' => $q->whereNotNull('r.reversed_at'),
            default => null,
        };
        $list = $q->orderBy('r.paid_on')->orderBy('r.id')->get(['r.id', 'r.number', 'r.paid_on', 'r.method', 'r.reference', 'r.amount_minor', 'r.unallocated_minor', 'r.reversed_at',
            't.name as tenant', 't.phone', 'u.code as unit', 'p.name as property', 'l.number as lease'])
            ->filter(fn ($r) => self::matches($x, [(string) $r->number, (string) $r->reference, (string) $r->tenant, (string) $r->phone, (string) $r->unit, (string) $r->lease]));
        $rows = [];
        $total = 0;
        $credit = 0;
        foreach ($list as $r) {
            $reversed = $r->reversed_at !== null;
            $total += $reversed ? 0 : (int) $r->amount_minor;
            $credit += $reversed ? 0 : (int) $r->unallocated_minor;
            $rows[] = ['number' => $r->number, 'date' => substr((string) $r->paid_on, 0, 10), 'tenant' => $r->tenant, 'unit' => $r->unit, 'property' => $r->property,
                'method' => self::METHOD_LABELS[$r->method] ?? (string) $r->method, 'reference' => $r->reference, 'status' => $reversed ? 'Reversed' : null,
                'amount_minor' => (int) $r->amount_minor, 'credit_minor' => (int) $r->unallocated_minor, '_peek' => 'payment:'.$r->id];
        }

        return [
            'columns' => [self::c('number', 'Receipt'), self::c('date', 'Date', 'date'), self::c('tenant', 'Tenant'), self::c('unit', 'Unit'), self::c('property', 'Property'), self::c('method', 'Paid by'),
                self::c('reference', 'Reference'), self::c('status', 'Status'), self::c('amount_minor', 'Amount', 'money'), self::c('credit_minor', 'Paid ahead', 'money')],
            'rows' => $rows,
            'totals' => ['amount_minor' => $total, 'credit_minor' => $credit, 'receipts' => count($rows)],
            'total_label' => 'Total, not counting reversed',
        ];
    }

    /** @return list<string> the filters a report takes */
    private static function filtersOf(string $key): array
    {
        return self::REPORTS[$key][2];
    }

    /** The filters of one run, checked: dates, the property, landlord and lease asked for (in the login's scope). */
    private function context(int $cid, ?User $by, array $params, string $key, bool $owner): object
    {
        $today = LocalDate::today($cid);
        $date = function (string $name, ?string $default) use ($params): ?string {
            $v = $params[$name] ?? null;
            if ($v === null || $v === '') {
                return $default;
            }
            $v = trim((string) $v);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
                throw BusinessRuleException::make('invalid_date', "The {$name} date must look like 2026-10-31.");
            }

            return $v;
        };
        $filters = self::filtersOf($key);
        $lease = null;
        if (in_array('lease', $filters, true) && ! empty($params['lease_id'])) {
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->find((int) $params['lease_id']);
            if ($lease === null || ! RentalAccess::inScope($by, (int) $lease->property_id)) {
                throw BusinessRuleException::make('not_found', 'That tenancy was not found.');
            }
        }
        $defaultFrom = $lease !== null ? $lease->start_date->toDateString() : $today->copy()->startOfMonth()->toDateString();
        $from = in_array('period', $filters, true) ? $date('from', $defaultFrom) : null;
        $to = in_array('period', $filters, true) ? $date('to', $today->toDateString()) : null;
        if (in_array('month', $filters, true)) {
            $month = trim((string) ($params['month'] ?? '')) ?: $today->format('Y-m');
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                throw BusinessRuleException::make('invalid_date', 'Choose a month, for example 2026-10.');
            }
            $from = $month.'-01';
            $to = Carbon::parse($from)->endOfMonth()->toDateString(); // the whole of the last day: every period is by business date
        }
        if (in_array('year', $filters, true)) {
            $year = trim((string) ($params['year'] ?? '')) ?: $today->format('Y');
            if (! preg_match('/^\d{4}$/', $year)) {
                throw BusinessRuleException::make('invalid_date', 'Choose a year, for example 2026.');
            }
            $from = $year.'-01-01';
            $to = $year.'-12-31';
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw BusinessRuleException::make('invalid_dates', 'The start date is after the end date.');
        }
        if (($from !== null && $from < Day::FIRST) || ($to !== null && $to > Day::LAST)) {
            throw BusinessRuleException::make('invalid_date', 'Choose dates this century: a report over thousands of years is a slip of the keyboard.'); // and it walks the months, for every door
        }
        $ids = RentalAccess::propertyIds($by);
        $property = null;
        if (in_array('property', $filters, true) && ! empty($params['property_id'])) {
            $property = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->find((int) $params['property_id']);
            if ($property === null || ! RentalAccess::inScope($by, (int) $property->id)) {
                throw BusinessRuleException::make('not_found', 'That property was not found.');
            }
            $ids = [(int) $property->id];
        }
        $landlord = null;
        if (in_array('landlord', $filters, true) && ! empty($params['landlord_id'])) {
            $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find((int) $params['landlord_id'])
                ?? throw BusinessRuleException::make('not_found', 'That landlord was not found.');
            if ($key !== 'landlord_statement') {
                $own = array_map('intval', DB::table('rental_properties')->where('company_id', $cid)->where('landlord_id', $landlord->id)->pluck('id')->all());
                $ids = $ids === null ? $own : array_values(array_intersect($ids, $own));
            }
        }
        $asOf = Carbon::parse($date('as_of', $today->toDateString()));

        return (object) ['cid' => $cid, 'by' => $by, 'today' => $today, 'from' => $from, 'to' => $to, 'asOf' => $asOf, 'ids' => $ids, 'property' => $property,
            'landlord' => $landlord, 'lease' => $lease, 'owner' => $owner, 'params' => $params];
    }

    private function subtitle(object $x): string
    {
        return ($x->from !== null ? $this->period($x) : $this->asAt($x)).$this->filterWords($x);
    }

    /** @return array{0: string, 1: string, 2: list<string>} */
    private static function ownerWords(string $key, string $title, string $description, array $filters): array
    {
        return match ($key) {
            'landlord_statement' => ['Property income statement', 'The property\'s account for the period: rent and fees collected, expenses, owner drawings and the money in hand.', ['period']],
            'income_statement' => ['Income statement', 'Rent and fees collected less the expenses paid in the period.', $filters],
            'expenses' => [$title, 'Money paid out for the property in the period.', $filters],
            'deposits_held' => [$title, 'Security deposits held for tenants today, per lease.', $filters],
            'landlord_year' => [$title, 'The property\'s year: collected by month, expenses, owner drawings, opening and closing. A summary of money handled, not tax advice.', ['year']],
            'trial_balance' => [$title, 'Opening, movement and closing per account for the period.', $filters],
            default => [$title, $description, $filters],
        };
    }

    /**
     * A report as an owner reads it: nothing that splits money between an agency and its landlords.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function ownerBody(array $body): array
    {
        $strip = function (array $table): array {
            $table['columns'] = array_values(array_filter($table['columns'], fn ($c) => ! in_array($c['key'], self::OWNER_HIDDEN_COLUMNS, true)));
            $table['rows'] = array_map(fn ($r) => array_diff_key((array) $r, array_flip(self::OWNER_HIDDEN_COLUMNS)), array_values($table['rows']));
            $table['totals'] = array_diff_key((array) ($table['totals'] ?? []), array_flip(self::OWNER_HIDDEN_TOTALS));

            return $table;
        };
        $body = $strip($body);
        $body['sections'] = array_map($strip, $body['sections'] ?? []);

        return $body;
    }

    /**
     * The days of [$from, $to] not covered by any of $covered (each [a, b], both days included).
     *
     * @param  list<array{0: string, 1: string}>  $covered
     * @return list<array{0: string, 1: string}>
     */
    public static function gaps(string $from, string $to, array $covered): array
    {
        if ($from > $to) {
            return [];
        }
        usort($covered, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        $cursor = $from;
        foreach ($covered as [$a, $b]) {
            if ($b < $cursor) {
                continue;
            }
            if ($a > $to) {
                break;
            }
            if ($a > $cursor) {
                $out[] = [$cursor, min($to, Carbon::parse($a)->subDay()->toDateString())];
            }
            $cursor = Carbon::parse(max($b, $cursor))->addDay()->toDateString();
            if ($cursor > $to) {
                return $out;
            }
        }
        $out[] = [$cursor, $to];

        return $out;
    }

    /** The asking rent of the days $a..$b, month by month (a month's rent over its days). */
    private static function rentFor(int $monthly, string $a, string $b): int
    {
        $sum = 0;
        $d = Carbon::parse($a);
        $end = Carbon::parse($b);
        while ($d->lte($end)) {
            $monthEnd = $d->copy()->endOfMonth()->startOfDay();
            $last = $monthEnd->lt($end) ? $monthEnd : $end;
            $sum += Minor::share($monthly, Periods::days($d, $last) + 1, $d->daysInMonth);
            $d = $last->copy()->addDay();
        }

        return $sum;
    }
}

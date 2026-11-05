<?php

namespace App\Services\Rentals\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\V1\Rentals\Present;
use App\Models\Company;
use App\Models\RentalCharge;
use App\Models\RentalExpense;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalLogin;
use App\Models\RentalMaintenance;
use App\Models\RentalPayment;
use App\Models\RentalPayout;
use App\Models\RentalProperty;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Models\StoredFile;
use App\Services\Rentals\Collections\Arrears;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Landlords\StatementPdf;
use App\Services\Rentals\Landlords\UnitSheet;
use App\Services\Rentals\Payments\Receipt;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use App\Support\LocalDate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a tenant or a landlord sees of their own records (KR-16, docs/estate-ledger/04-architecture.md §8, §10.3): the read
 * models of the API's `rentals/me/*` and the web portal's `/my`, keyed only by the RentalLogin, never by a request
 * parameter. A tenant sees their own leases, bills, payments, receipts and repair requests; a landlord their own
 * properties (units, state and who pays: never a tenant's ID number or phone), statements, payouts and arrears. A
 * record that is not theirs is not found. Shapes are the API's (§9.1), less what is between the agency and the
 * landlord, and the staff's own notes.
 */
final class PersonView
{
    private ?Present $present = null;

    private RentalTenant|RentalLandlord|null $subject = null;

    public function __construct(public readonly RentalLogin $login) {}

    public static function for(RentalLogin $login): self
    {
        return new self($login);
    }

    public function companyId(): int
    {
        return (int) $this->login->company_id;
    }

    public function isTenant(): bool
    {
        return $this->login->subject_type === 'tenant';
    }

    public function isLandlord(): bool
    {
        return $this->login->subject_type === 'landlord';
    }

    public function subject(): RentalTenant|RentalLandlord
    {
        return $this->subject ??= ($this->login->subject() ?? throw BusinessRuleException::make('portal_session_invalid', 'Please sign in again.'));
    }

    public function present(): Present
    {
        return $this->present ??= new Present($this->companyId(), 'portal', (int) $this->login->id);
    }

    /** §9.1 Person: {type, account, tenant | landlord} (a landlord's also has `totals`). @return array<string, mixed> */
    public function me(): array
    {
        $out = ['type' => (string) $this->login->subject_type, 'account' => $this->account()];
        if ($this->isTenant()) {
            /** @var RentalTenant $t */
            $t = $this->subject();
            $out['tenant'] = $this->present()->tenant($t, Figures::tenant($t), true, true);
        } else {
            /** @var RentalLandlord $l */
            $l = $this->subject();
            $out['landlord'] = $this->present()->landlord($l, Figures::landlords($this->companyId(), [(int) $l->id])[(int) $l->id] ?? null, true);
            $out['totals'] = $this->summary();
        }

        return $out;
    }

    /** {id, name, currency, payment_instructions, phone} of the person's account; `phone` is the business's own number, so they can reach it. @return array<string, mixed> */
    public function account(): array
    {
        $s = RentalsModule::settings($this->companyId());
        $company = Company::withoutGlobalScopes()->whereKey($this->companyId())->first(['id', 'name', 'phone_number', 'phone_number_2']);

        return ['id' => $this->companyId(), 'name' => (string) $company?->name,
            'currency' => (string) $s->currency, 'payment_instructions' => $s->payment_instructions, 'phone' => trim((string) ($company?->phone_number ?: $company?->phone_number_2)) ?: null];
    }

    // ── A tenant ────────────────────────────────────────────────────────────

    /** The tenant's leases (all but cancelled ones), newest first. @return Collection<int, RentalLease> */
    public function leases(): Collection
    {
        return $this->tenantLeases()->orderByDesc('start_date')->orderByDesc('id')->get();
    }

    /**
     * One of the tenant's leases, as Lease (§9.1) without the agency's side of it, and the notice they sent that the
     * office has not confirmed yet (`notice_request`, TenantRequests); null when it is not theirs.
     */
    public function lease(int $id): ?array
    {
        $lease = $this->tenantLeases()->find($id);
        if ($lease === null) {
            return null;
        }
        $pending = $lease->status === 'active' ? (TenantRequests::pendingNotices($this->companyId(), [(int) $lease->id])[(int) $lease->id] ?? null) : null;

        return $this->present()->lease($lease, true) + ['notice_request' => $pending === null ? null
            : ['move_out_on' => $pending['move_out_on'], 'notes' => $pending['notes'], 'recorded_at' => $pending['recorded_at']]];
    }

    /**
     * The tenant's homes as LeaseBrief (§9.1), newest first: the ones they live in now ($liveOnly), or every lease but
     * cancelled ones.
     *
     * @return list<array<string, mixed>>
     */
    public function homes(bool $liveOnly = true): array
    {
        $q = $this->tenantLeases();
        if ($liveOnly) {
            $q->whereIn('status', RentalLease::LIVE);
        }
        $leases = $q->orderByDesc('start_date')->orderByDesc('id')->get();
        $present = $this->present()->prime($leases)->primePositions($leases);

        return $leases->map(fn (RentalLease $l) => $present->leaseBrief($l))->all();
    }

    /** The tenant's bills (not cancelled ones, nor refunds, unless asked by status), newest first. */
    public function charges(?int $leaseId = null, ?string $status = null, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        $q = RentalCharge::withoutGlobalScopes()->where('company_id', $this->companyId())->where('tenant_id', $this->tenantId())
            ->whereIn('lease_id', $this->tenantLeases()->select('id'))->where('kind', '!=', 'refund');
        if ($leaseId !== null) {
            $q->where('lease_id', $leaseId);
        }
        in_array($status, ['open', 'paid', 'void'], true) ? $q->where('status', $status) : $q->where('status', '!=', 'void');
        $p = $q->orderByDesc('due_date')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return $p->through(fn ($c) => $this->present()->charge($c, true));
    }

    /** The tenant's payments, newest first. */
    public function payments(?int $leaseId = null, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        $q = RentalPayment::withoutGlobalScopes()->where('company_id', $this->companyId())->where('tenant_id', $this->tenantId());
        if ($leaseId !== null) {
            $q->where('lease_id', $leaseId);
        }
        $p = $q->orderByDesc('paid_on')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $this->present()->prime($p->items());

        return $p->through(fn ($x) => $this->present()->paymentBrief($x));
    }

    /** One of the tenant's payments (Payment, §9.1, without the agency's split), with its receipt; null when it is not theirs. */
    public function payment(int $id): ?array
    {
        $p = $this->tenantPayment($id);

        return $p === null ? null : $this->present()->prime([$p])->payment($p, $this->receiptOf($p), true);
    }

    /** The receipt of one of the tenant's payments: {text, pdf_url}; null when it is not theirs. */
    public function receipt(int $paymentId): ?array
    {
        $p = $this->tenantPayment($paymentId);

        return $p === null ? null : $this->receiptOf($p);
    }

    /**
     * The receipt of one of the tenant's payments as a PDF, for a page that serves it from the person's own session;
     * null when it is not theirs.
     *
     * @return array{bytes: string, filename: string}|null
     */
    public function receiptFile(int $paymentId): ?array
    {
        $p = $this->tenantPayment($paymentId);

        return $p === null ? null : ['bytes' => Receipt::pdf($p), 'filename' => 'receipt-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $p->number).'.pdf'];
    }

    /**
     * The tenant's statement for one of their homes as a PDF: every bill and payment with the running balance and the
     * deposit, from the day the lease began to today. The same report the office prints. Null when the lease is not theirs.
     *
     * @return array{bytes: string, filename: string}|null
     */
    public function tenantStatementFile(int $leaseId): ?array
    {
        $lease = $this->tenantLeases()->find($leaseId);
        if ($lease === null) {
            return null;
        }
        $report = (new RentalReports)->run('tenant_statement', $this->companyId(), null, ['lease_id' => (int) $lease->id,
            'from' => $lease->start_date->toDateString(), 'to' => LocalDate::today($this->companyId())->toDateString()]);

        return ['bytes' => RentalExport::pdf($report, $this->companyId()), 'filename' => 'statement-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $lease->number).'.pdf'];
    }

    /** The repair requests about the tenant's homes (theirs, and staff's about their unit), newest first. */
    public function maintenance(int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        $p = RentalMaintenance::withoutGlobalScopes()->where('company_id', $this->companyId())->where('tenant_id', $this->tenantId())
            ->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $this->present()->prime($p->items());

        return $p->through(fn ($m) => $this->present()->maintenance($m, true));
    }

    // ── A landlord ──────────────────────────────────────────────────────────

    /** Owed to the landlord now, collected for them this month, and the occupancy of their properties. @return array<string, mixed> */
    public function summary(): array
    {
        $l = $this->landlord();
        $today = LocalDate::today($this->companyId());
        $month = LandlordAccounts::statement($l, $today->copy()->startOfMonth()->toDateString(), $today->toDateString());

        return ['currency' => $month['currency'], 'owed_minor' => LandlordAccounts::balance($l), 'collected_month_minor' => (int) $month['totals']['collected_minor'],
            'occupancy' => LandlordAccounts::occupancy($l)];
    }

    /**
     * The landlord's properties (Property, §9.1, without the staff who look after them), each with its units: state,
     * rent, and who pays (the tenant's name and where they stand), never a tenant's ID number or phone.
     */
    public function properties(int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        $cid = $this->companyId();
        $p = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $this->landlord()->id)->where('status', 'active')
            ->orderBy('name')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
        $ids = collect($p->items())->pluck('id')->map(fn ($x) => (int) $x)->all();
        $figures = Figures::properties($cid, $ids);
        $units = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', $ids ?: [0])->where('status', '!=', 'inactive')
            ->orderByRaw('LENGTH(code), code')->get()->groupBy('property_id');
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', $ids ?: [0])->whereIn('status', RentalLease::LIVE)
            ->orderByDesc('id')->get()->unique('unit_id')->keyBy('unit_id');
        $present = $this->present()->prime($p->items())->prime($leases->values())->primePositions($leases->values())->primeFiles(collect($p->items())->pluck('photo_uuid'));

        return $p->through(fn ($prop) => $present->property($prop, $figures[(int) $prop->id] ?? null, false) + ['units' => ($units->get($prop->id) ?? collect())
            ->map(fn ($u) => $this->unitForLandlord($u, $leases->get($u->id)))->values()->all()]);
    }

    /**
     * The landlord's statement for a month ('Y-m'; the month so far for the current one): the issued copy of a closed month, else the journal as it
     * stands, with a stamp that says so (StatementBook::read).
     */
    public function statement(string $month): array
    {
        [$from, $to] = $this->monthRange($month);

        return StatementBook::read($this->landlord(), $from, $to);
    }

    /** The landlord's closed months with an issued statement: Y-m => its number and day. @return array<string, array{number: string, issued_on: ?string}> */
    public function issuedMonths(): array
    {
        return StatementBook::issuedMonths($this->landlord());
    }

    /** A 30-minute link to the statement's PDF. @return array{url: string, expires_in: int} */
    public function statementPdf(string $month): array
    {
        [$from, $to] = $this->monthRange($month);

        return RentalLinks::statementPdf($this->landlord(), $from, $to, 'portal', (int) $this->login->id);
    }

    /**
     * The landlord's statement for a month as a PDF, for a page that serves it from the person's own session.
     *
     * @return array{bytes: string, filename: string}
     */
    public function statementFile(string $month): array
    {
        [$from, $to] = $this->monthRange($month);
        $landlord = $this->landlord();
        if (($copy = StatementBook::current($landlord, $month)) !== null) {
            StatementBook::markOpened($copy); // the landlord, on their own sign-in
        }

        return ['bytes' => StatementPdf::render($landlord, $from, $to), 'filename' => StatementPdf::filename($landlord, $from, $to)];
    }

    /** Money paid to the landlord, newest first. */
    public function payouts(int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        $p = RentalPayout::withoutGlobalScopes()->where('company_id', $this->companyId())->where('landlord_id', $this->landlord()->id)
            ->orderByDesc('paid_on')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $this->present()->prime($p->items());

        return $p->through(function ($x) {
            $shape = $this->present()->payout($x);
            unset($shape['notes'], $shape['advance_reason']); // the staff's own words: the web's page has never shown them

            return $shape;
        });
    }

    /** A 30-minute link to the advice of one of the landlord's payouts; null when it is not theirs, or the account has no landlords to advise. @return array{url: string, expires_in: int}|null */
    public function payoutAdviceLink(int $id): ?array
    {
        $p = RentalPayout::withoutGlobalScopes()->where('company_id', $this->companyId())->where('landlord_id', $this->landlord()->id)->find($id);

        return $p === null || RentalsModule::isOwnerMode($this->companyId()) ? null : RentalLinks::payoutAdvicePdf($p, 'portal', (int) $this->login->id);
    }

    /**
     * The landlord's units for a month, door by door (UnitSheet, the rent sheet the office reads): who paid, who owes, which stand empty. Names and figures only: no phone,
     * no ID number, nothing of the agency's. The current month is as of today.
     *
     * @return array{month: string, as_of: string, currency: string, rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function monthSheet(string $month): array
    {
        [, $to] = $this->monthRange($month);
        $rows = UnitSheet::rows($this->landlord(), $to);
        $sum = fn (string $k) => (int) array_sum(array_column($rows, $k));

        return ['month' => $month, 'as_of' => $to, 'currency' => (string) RentalsModule::settings($this->companyId())->currency,
            'rows' => array_map(fn (array $r) => array_diff_key($r, array_flip(['lease_id', 'tenant_id', 'origin'])), $rows),
            'totals' => ['rent_minor' => $sum('rent_minor'), 'billed_minor' => $sum('billed_minor'), 'paid_minor' => $sum('paid_minor'), 'direct_minor' => $sum('direct_minor'), 'owed_minor' => $sum('owed_minor')]];
    }

    /**
     * A 30-minute link to the landlord's year summary as a PDF or Excel file.
     *
     * @return array{url: string, expires_in: int}
     */
    public function yearSummaryLink(string $year, string $format): array
    {
        $first = $this->firstStatementYear();
        $thisYear = (int) LocalDate::today($this->companyId())->format('Y');
        if (preg_match('/^\d{4}$/', $year) !== 1 || (int) $year < $first || (int) $year > $thisYear) {
            throw BusinessRuleException::make('invalid_date', "Choose a year from {$first} to {$thisYear}.");
        }
        if (! in_array($format, ['pdf', 'xlsx'], true)) {
            throw BusinessRuleException::make('unknown_format', 'Ask for the summary as pdf or xlsx.');
        }

        return RentalLinks::yearSummary($this->landlord(), $year, $format, 'portal', (int) $this->login->id);
    }

    /**
     * Arrears on the landlord's properties (read only): per lease, the unit, the tenant's name, what is owed and
     * overdue and for how many days (Arrears::list), biggest first; never a tenant's phone.
     */
    public function arrears(int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        $cid = $this->companyId();
        $props = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $this->landlord()->id)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $rows = array_values(array_filter(Arrears::list($cid), fn ($r) => in_array((int) $r['property']['id'], $props, true)));
        $slice = array_slice($rows, (int) min(count($rows), ($page - 1) * $perPage), $perPage); // a page past any number there is: an empty one, not an overflow
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', array_column($slice, 'lease_id') ?: [0])->get()->keyBy('id');
        $present = $this->present()->prime($leases->values())->primePositions($leases->values());
        $items = [];
        foreach ($slice as $r) {
            if (($lease = $leases->get($r['lease_id'])) === null) {
                continue;
            }
            $brief = $present->leaseBrief($lease, null, false);
            $items[] = $brief + ['lease_id' => (int) $lease->id, 'owed_minor' => (int) $brief['position']['owed_minor'], 'overdue_minor' => (int) $r['overdue_minor'],
                'days' => (int) $r['days'], 'bucket' => $r['bucket']];
        }

        return new Paginator($items, count($rows), $perPage, $page);
    }

    /**
     * What a landlord's first page answers (docs/estate-ledger/03-improvements.md §12): what they are owed, this month so
     * far, the last money paid to them, which units stand empty and how many repairs are open.
     *
     * @return array{owed_minor: int, month: array<string, int>, last_payout: array{paid_on: string, amount_minor: int, method: string}|null, occupancy: array<string, int>, vacant: list<array{code: string, property: string, rent_minor: int}>, repairs_open: int}
     */
    public function landlordHome(int $vacantShown = 8): array
    {
        $l = $this->landlord();
        $cid = $this->companyId();
        $today = LocalDate::today($cid);
        $month = LandlordAccounts::statement($l, $today->copy()->startOfMonth()->toDateString(), $today->toDateString());
        $last = RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $l->id)->whereNull('reversed_at')->orderByDesc('paid_on')->orderByDesc('id')->first();
        $props = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $l->id)->where('status', 'active')->pluck('name', 'id');
        $let = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', $props->keys()->all() ?: [0])->whereIn('status', RentalLease::LIVE)->select('unit_id');
        $vacant = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', $props->keys()->all() ?: [0])->whereNotIn('status', ['inactive', 'in_use'])
            ->whereNotIn('id', $let)->orderBy('property_id')->orderByRaw('LENGTH(code), code')->limit($vacantShown)->get();

        return ['owed_minor' => LandlordAccounts::balance($l), 'month' => array_map('intval', $month['totals']),
            'last_payout' => $last === null ? null : ['paid_on' => (string) $last->paid_on->toDateString(), 'amount_minor' => (int) $last->amount_minor, 'method' => (string) $last->method],
            'occupancy' => LandlordAccounts::occupancy($l),
            'vacant' => $vacant->map(fn (RentalUnit $u) => ['code' => (string) $u->code, 'property' => (string) ($props[$u->property_id] ?? ''), 'rent_minor' => (int) $u->rent_minor])->all(),
            'repairs_open' => (int) $this->landlordRepairs()->whereIn('status', ['open', 'in_progress'])->count()];
    }

    /**
     * The repairs at the landlord's properties, newest first, to read: what, where, its state, its cost and any photo.
     * Never which staff member has it: that is between the office and its people.
     */
    public function repairs(int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $p = $this->landlordRepairs()->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $this->present()->prime($p->items());

        return $p->through(function (RentalMaintenance $m) {
            $shape = $this->present()->maintenance($m);
            unset($shape['assigned_to'], $shape['assignee_name'], $shape['expense_id']);

            return $shape;
        });
    }

    /** What was spent and charged to the landlord, newest first, each with its receipt image when the office kept one. */
    public function expenses(int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $p = RentalExpense::withoutGlobalScopes()->where('company_id', $this->companyId())->where('landlord_id', $this->landlord()->id)->where('charged_to', 'landlord')
            ->orderByDesc('paid_on')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $this->present()->prime($p->items());
        $uuids = collect($p->items())->pluck('receipt_uuid')->filter()->all();
        $mimes = $uuids === [] ? [] : StoredFile::withoutGlobalScopes()->where('company_id', $this->companyId())->whereIn('uuid', $uuids)->pluck('mime', 'uuid')->all();

        return $p->through(fn (RentalExpense $e) => $this->present()->expense($e) + ['receipt_is_image' => str_starts_with((string) ($mimes[(string) $e->receipt_uuid] ?? ''), 'image/')]);
    }

    /** The first year this landlord has anything to read a statement for: their oldest lease, or this year. */
    public function firstStatementYear(): int
    {
        $cid = $this->companyId();
        $props = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $this->landlord()->id)->pluck('id')->all();
        $oldest = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', $props ?: [0])->where('status', '!=', 'cancelled')->min('start_date');
        $year = (int) LocalDate::today($cid)->format('Y');

        return $oldest === null ? $year : min($year, (int) substr((string) $oldest, 0, 4));
    }

    // ── Inside ──────────────────────────────────────────────────────────────

    private function tenantId(): int
    {
        if (! $this->isTenant()) {
            throw BusinessRuleException::make('not_found', 'This is for tenants.');
        }

        return (int) $this->login->subject_id;
    }

    private function landlord(): RentalLandlord
    {
        if (! $this->isLandlord()) {
            throw BusinessRuleException::make('not_found', 'This is for landlords.');
        }
        /** @var RentalLandlord $l */
        $l = $this->subject();

        return $l;
    }

    /** @return Builder<RentalMaintenance> */
    private function landlordRepairs(): Builder
    {
        $cid = $this->companyId();
        $props = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $this->landlord()->id)->pluck('id')->all();

        return RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', $props ?: [0]);
    }

    /** @return Builder<RentalLease> */
    private function tenantLeases(): Builder
    {
        return RentalLease::withoutGlobalScopes()->where('company_id', $this->companyId())->where('tenant_id', $this->tenantId())->where('status', '!=', 'cancelled');
    }

    private function tenantPayment(int $id): ?RentalPayment
    {
        return RentalPayment::withoutGlobalScopes()->where('company_id', $this->companyId())->where('tenant_id', $this->tenantId())->find($id);
    }

    /** @return array{text: string, pdf_url: string} */
    private function receiptOf(RentalPayment $p): array
    {
        return ['text' => Receipt::text($p), 'pdf_url' => RentalLinks::receiptPdf($p, 'portal', (int) $this->login->id)['url']];
    }

    /** A unit as its landlord sees it: who pays and where they stand, no phone, no ID number. @return array<string, mixed> */
    private function unitForLandlord(RentalUnit $u, ?RentalLease $lease): array
    {
        $shape = $this->present()->unit($u, $lease, null, false);
        unset($shape['notes']);

        return $shape;
    }

    /** @return array{0: string, 1: string} the first and last day of a 'Y-m' month (today for the current one) */
    private function monthRange(string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw BusinessRuleException::make('date_invalid', 'Give the month like 2026-10.');
        }
        $start = Carbon::parse($month.'-01');
        $today = LocalDate::today($this->companyId());
        if ($start->gt($today)) {
            throw BusinessRuleException::make('date_in_future', 'That month has not started yet.');
        }
        $end = $start->copy()->endOfMonth()->startOfDay();

        return [$start->toDateString(), ($end->gt($today) ? $today : $end)->toDateString()];
    }
}

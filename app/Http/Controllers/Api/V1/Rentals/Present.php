<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalActivity;
use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalExpense;
use App\Models\RentalFollowUp;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalLeaseItem;
use App\Models\RentalMaintenance;
use App\Models\RentalMeterReading;
use App\Models\RentalPayment;
use App\Models\RentalPayout;
use App\Models\RentalProperty;
use App\Models\RentalRentChange;
use App\Models\RentalSetting;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Services\Rentals\Collections\FollowUps;
use App\Services\Rentals\Directory\Directory;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Leases\CommissionChanges;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Messages\RentalMessages;
use App\Services\Rentals\Payments\Receipt;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Periods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Estate Ledger API's shapes (docs/estate-ledger/04-architecture.md §9.1; the phone app and the portal read these names):
 * money as `*_minor` integers with `currency` where listed, business dates `Y-m-d`, instants ISO 8601 UTC, objects
 * null when empty. Never an unmasked ID number, a password, or another account's record: every name it looks up is
 * read within the one account it was made for. One instance per request (it remembers the account's settings, its
 * day and the names it looked up, so a list costs a few queries, not one per row).
 */
final class Present
{
    /** kind => [table, columns]: the names a record points at. */
    private const LOOKUPS = [
        'unit' => ['rental_units', ['id', 'code']],
        'property' => ['rental_properties', ['id', 'name']],
        'tenant' => ['rental_tenants', ['id', 'name', 'phone', 'phone_e164']],
        'landlord' => ['rental_landlords', ['id', 'name']],
        'lease' => ['rental_leases', ['id', 'number']],
        'user' => ['admin_users', ['id', 'name']],
    ];

    /** Columns of a record that point at a name. */
    private const POINTERS = ['unit_id' => 'unit', 'property_id' => 'property', 'tenant_id' => 'tenant', 'landlord_id' => 'landlord', 'lease_id' => 'lease',
        'received_by_id' => 'user', 'created_by_id' => 'user', 'assigned_to_id' => 'user'];

    private ?RentalSetting $settings = null;

    private ?Carbon $today = null;

    /** @var array<string, array<int, ?object>> */
    private array $names = [];

    /** @var array<string, ?string> */
    private array $files = [];

    /** @var array<int, array<string, mixed>> lease id => its position, read together for a page */
    private array $positions = [];

    /** @var array<int, list<array{user_id: int, name: string}>> property id => who looks after it, read together for a page */
    private array $staff = [];

    /** A login tied to some properties: how the money is shared with the landlord, and what a landlord is owed, are between the office and the landlord (EL-20, EL-26). */
    private bool $tied = false;

    /** $viewer, $viewerId: who the rows are for (RentalLinks::VIEWERS); a private file's link is made for that person alone. */
    public function __construct(public readonly int $cid, private readonly ?string $viewer = null, private readonly ?int $viewerId = null) {}

    public function tiedToSomeProperties(): self
    {
        $this->tied = true;

        return $this;
    }

    public function settingsRow(): RentalSetting
    {
        return $this->settings ??= RentalsModule::settings($this->cid);
    }

    public function currency(): string
    {
        return (string) $this->settingsRow()->currency;
    }

    public function today(): Carbon
    {
        return ($this->today ??= LocalDate::today($this->cid))->copy();
    }

    /** Look up, in one query per kind, the names the rows about to be shown point at. @param iterable<Model|array<string, mixed>> $rows */
    public function prime(iterable $rows): self
    {
        $want = [];
        foreach ($rows as $r) {
            foreach (self::POINTERS as $col => $kind) {
                $v = $r instanceof Model ? $r->getAttribute($col) : ($r[$col] ?? null);
                if ($v) {
                    $want[$kind][(int) $v] = true;
                }
            }
        }
        foreach ($want as $kind => $ids) {
            $this->load($kind, array_keys($ids));
        }

        return $this;
    }

    /** Look up the signed links of many files at once. @param iterable<?string> $uuids */
    public function primeFiles(iterable $uuids): self
    {
        $todo = [];
        foreach ($uuids as $u) {
            if (is_string($u) && $u !== '' && ! array_key_exists($u, $this->files)) {
                $todo[] = $u;
            }
        }
        if ($todo !== []) {
            $urls = RentalLinks::files($this->cid, $todo, $this->viewer, $this->viewerId);
            foreach ($todo as $u) {
                $this->files[$u] = $urls[$u] ?? null;
            }
        }

        return $this;
    }

    public function fileUrl(?string $uuid): ?string
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $this->primeFiles([$uuid]);

        return $this->files[$uuid] ?? null;
    }

    /** {id, code} of a unit, {id, number} of a lease, {id, name} of anything else; null when it is not there. */
    public function ref(string $kind, mixed $id): ?array
    {
        $r = $this->row($kind, $id);
        if ($r === null) {
            return null;
        }

        return match ($kind) {
            'unit' => ['id' => (int) $r->id, 'code' => (string) $r->code],
            'lease' => ['id' => (int) $r->id, 'number' => (string) $r->number],
            default => ['id' => (int) $r->id, 'name' => (string) $r->name],
        };
    }

    /** {id, name, phone} of a tenant (phone left out where the viewer must not see it). */
    public function tenantRef(mixed $id, bool $phone = true): ?array
    {
        $r = $this->row('tenant', $id);
        if ($r === null) {
            return null;
        }

        return ['id' => (int) $r->id, 'name' => (string) $r->name] + ($phone ? ['phone' => self::str($r->phone ?: $r->phone_e164)] : []);
    }

    /** Position (Leases::position). @return array<string, mixed> */
    public function position(RentalLease $l): array
    {
        return $this->positions[(int) $l->id] ?? Leases::position($l, $this->today());
    }

    /** Read the positions of a page of leases together: a few queries, not several per row. @param iterable<RentalLease> $leases */
    public function primePositions(iterable $leases): self
    {
        $list = [];
        foreach ($leases as $l) {
            $list[] = $l;
        }
        if ($list !== []) {
            $this->positions = Leases::positions($list, $this->today()) + $this->positions;
        }

        return $this;
    }

    /** Read who looks after a page of properties together. @param iterable<int|string> $propertyIds */
    public function primeStaff(iterable $propertyIds): self
    {
        $ids = [];
        foreach ($propertyIds as $id) {
            $ids[(int) $id] = true;
            $this->staff[(int) $id] = [];
        }
        if ($ids !== []) {
            foreach (DB::table('rental_property_staff as s')->join('admin_users as u', 'u.id', '=', 's.user_id')->where('s.company_id', $this->cid)->whereIn('s.property_id', array_keys($ids))
                ->orderBy('u.name')->get(['s.property_id', 's.user_id', 'u.name']) as $r) {
                $this->staff[(int) $r->property_id][] = ['user_id' => (int) $r->user_id, 'name' => (string) $r->name];
            }
        }

        return $this;
    }

    /** LeaseBrief. @param array<string, mixed>|null $position */
    public function leaseBrief(RentalLease $l, ?array $position = null, bool $phones = true): array
    {
        return ['id' => (int) $l->id, 'number' => (string) $l->number, 'status' => (string) $l->status, 'start_date' => self::day($l->start_date), 'end_date' => self::day($l->end_date),
            'rent_minor' => (int) $l->rent_minor, 'cycle_months' => (int) $l->cycle_months, 'currency' => $this->currency(), 'unit' => $this->ref('unit', $l->unit_id),
            'property' => $this->ref('property', $l->property_id), 'tenant' => $this->tenantRef($l->tenant_id, $phones), 'position' => $position ?? $this->position($l)];
    }

    /**
     * Lease: LeaseBrief with its terms, fees, rent history, bills (newest first, up to 100), payments and follow-ups.
     * A tenant's own view leaves out what is between the agency and the landlord (commission, the landlord, notes,
     * the collections log).
     */
    public function lease(RentalLease $l, bool $forTenant = false): array
    {
        $cid = $this->cid;
        $charges = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $l->id)->orderByDesc('due_date')->orderByDesc('id')->limit(100)->get();
        $payments = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $l->id)->orderByDesc('paid_on')->orderByDesc('id')->limit(100)->get();
        $followUps = $forTenant ? collect() : FollowUps::forLease($l, 50);
        $this->prime([$l])->prime($payments)->prime($followUps);

        return $this->leaseBrief($l) + [
            'landlord' => $forTenant ? null : $this->ref('landlord', $l->landlord_id),
            'anchor' => (string) $l->anchor, 'deposit_minor' => (int) $l->deposit_minor, 'deposit_held_by' => (string) $l->deposit_held_by,
            'commission' => $forTenant || $this->tied ? null : self::commission($l->commission_type ?? 'none', $l->commission_bp, $l->commission_minor),
            'late_fee' => (string) $l->late_fee, 'billed_until' => self::day($l->billed_until), 'notice_on' => self::day($l->notice_on), 'move_out_on' => self::day($l->move_out_on),
            'ended_on' => self::day($l->ended_on), 'end_reason' => $l->end_reason, 'notes' => $forTenant ? null : $l->notes,
            'term_ended' => Leases::termEnded($l, $this->today()),
            'commission_changes' => $forTenant || $this->tied ? [] : CommissionChanges::history($l)->map(fn ($c) => ['effective_from' => self::day($c->effective_from), 'commission' => self::commission($c->commission_type, $c->commission_bp, $c->commission_minor), 'reason' => $c->reason])->all(),
            'items' => RentalLeaseItem::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $l->id)->orderBy('id')->get()->map(fn ($i) => $this->item($i, $forTenant))->all(),
            'rent_changes' => RentalRentChange::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $l->id)->orderBy('effective_from')->orderBy('id')->get()
                ->map(fn ($c) => ['rent_minor' => (int) $c->rent_minor, 'effective_from' => self::day($c->effective_from), 'reason' => $c->reason])->all(),
            'charges' => $charges->map(fn ($c) => $this->charge($c, $forTenant))->all(),
            'payments' => $payments->map(fn ($p) => $this->paymentBrief($p))->all(),
            'follow_ups' => $followUps->map(fn ($f) => $this->followUp($f))->values()->all(),
        ];
    }

    /** $forTenant: who a fee goes to, and whether it earns commission, are between the agency and the landlord. */
    public function item(RentalLeaseItem $i, bool $forTenant = false): array
    {
        return ['id' => (int) $i->id, 'name' => (string) $i->name, 'amount_minor' => (int) $i->amount_minor] + ($forTenant ? [] : ['goes_to' => (string) $i->goes_to, 'commissionable' => (bool) $i->commissionable])
            + ['starts_on' => self::day($i->starts_on), 'ends_on' => self::day($i->ends_on), 'active' => (bool) $i->active];
    }

    /** Charge: overdue once open past its due date and the grace days; days counted from the due date. $forTenant: not who it goes to. */
    public function charge(RentalCharge $c, bool $forTenant = false): array
    {
        $today = $this->today();
        $overdue = $c->status === 'open' && $c->due_date->copy()->addDays((int) $this->settingsRow()->grace_days)->lt($today);

        return ['id' => (int) $c->id, 'lease_id' => (int) $c->lease_id, 'kind' => (string) $c->kind, 'description' => (string) $c->description,
            'period_start' => self::day($c->period_start), 'period_end' => self::day($c->period_end), 'due_date' => self::day($c->due_date),
            'amount_minor' => (int) $c->amount_minor, 'paid_minor' => (int) $c->paid_minor, 'left_minor' => $c->status === 'void' ? 0 : $c->left(), 'status' => (string) $c->status]
            + ($forTenant ? [] : ['goes_to' => (string) $c->goes_to]) + ['overdue' => $overdue, 'days_overdue' => $overdue ? max(0, Periods::days($c->due_date, $today)) : 0];
    }

    public function paymentBrief(RentalPayment $p): array
    {
        return ['id' => (int) $p->id, 'uuid' => (string) $p->uuid, 'number' => (string) $p->number, 'paid_on' => self::day($p->paid_on), 'amount_minor' => (int) $p->amount_minor,
            'method' => (string) $p->method, 'reference' => $p->reference, 'reversed' => $p->reversed_at !== null, 'tenant' => $this->tenantRef($p->tenant_id, false),
            'unit' => $this->ref('unit', $p->unit_id), 'property' => $this->ref('property', $p->property_id)];
    }

    /**
     * Payment: PaymentBrief with its lease, the split per bill, what is still owed and "paid up to" now, and the
     * receipt. A tenant's own view shows what each part settled, not how the agency and the landlord share it.
     *
     * @param  array{text: string, pdf_url: ?string}  $receipt
     */
    public function payment(RentalPayment $p, array $receipt, bool $forTenant = false): array
    {
        $cid = $this->cid;
        $allocs = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->where('payment_id', $p->id)->orderBy('id')->get();
        $descriptions = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $allocs->pluck('charge_id')->all() ?: [0])->pluck('description', 'id');
        $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->find($p->lease_id);
        $pos = (Receipt::hasSnapshots() ? ($p->receipt_snapshot['figures'] ?? null) : null) ?? ($lease !== null ? $this->position($lease) : null);

        return $this->paymentBrief($p) + [
            'lease' => $this->ref('lease', $p->lease_id), 'currency' => $this->currency(), 'received_by' => $this->ref('user', $p->received_by_id),
            'channel' => (string) $p->channel, 'notes' => $forTenant ? null : $p->notes, 'unallocated_minor' => (int) $p->unallocated_minor,
            'allocations' => $allocs->map(fn ($a) => ['charge_id' => (int) $a->charge_id, 'description' => (string) ($descriptions[$a->charge_id] ?? ''), 'source' => (string) $a->source,
                'amount_minor' => (int) $a->amount_minor] + ($forTenant || $this->tied ? [] : ['commission_minor' => (int) $a->commission_minor, 'landlord_minor' => (int) $a->landlord_minor,
                    'agency_minor' => (int) $a->agency_minor]) + ($forTenant ? [] : ['deposit_minor' => (int) $a->deposit_minor]))->all(),
            'reversed_at' => $p->reversed_at?->toIso8601String(), 'reverse_reason' => $p->reverse_reason,
            'owed_after_minor' => (int) ($pos['owed_minor'] ?? 0), 'paid_up_to' => $pos['paid_up_to'] ?? null, 'receipt' => $receipt,
        ];
    }

    /**
     * Landlord. $own: the landlord's own view (no staff notes, no limit on repairs). $payout false (a caretaker or anyone else without rentals_landlords, §7): how the landlord is paid,
     * their bank or mobile-money numbers, their limit on repairs, the staff notes that may hold them, their balance and their commission are null.
     *
     * @param  array{properties_count: int, units_count: int, balance_minor: int}|null  $figures  Figures::landlords
     */
    public function landlord(RentalLandlord $l, ?array $figures = null, bool $own = false, bool $payout = true): array
    {
        return ['id' => (int) $l->id, 'code' => $l->code, 'name' => (string) $l->name, 'phone' => $l->phone, 'phone_2' => $payout ? $l->phone_2 : null, 'email' => $l->email, 'address' => $l->address,
            'payout_method' => $payout ? (string) $l->payout_method : null, 'payout_details' => $payout ? $l->payout_details : null,
            'withholding_bp' => $payout && $l->withholding_bp !== null ? (int) $l->withholding_bp : null,
            'expense_limit_minor' => $payout && ! $own && $l->expense_limit_minor !== null ? (int) $l->expense_limit_minor : null,
            'commission' => ! $payout || $l->commission_type === null ? null : self::commission($l->commission_type, $l->commission_bp, $l->commission_minor),
            'is_self' => (bool) $l->is_self, 'status' => (string) $l->status, 'properties_count' => (int) ($figures['properties_count'] ?? 0),
            'units_count' => (int) ($figures['units_count'] ?? 0), 'balance_minor' => $payout ? (int) ($figures['balance_minor'] ?? 0) : null, 'notes' => $own || ! $payout ? null : $l->notes];
    }

    /**
     * Property. $staff: who looks after it and the staff's notes (left out of a landlord's own view).
     *
     * @param  array{units_count: int, occupied_count: int, vacant_count: int, occupancy_pct: float, arrears_minor: int}|null  $figures  Figures::properties
     */
    public function property(RentalProperty $p, ?array $figures = null, bool $staff = true): array
    {
        $team = [];
        if ($staff) {
            $team = $this->staff[(int) $p->id] ?? DB::table('rental_property_staff as s')->join('admin_users as u', 'u.id', '=', 's.user_id')->where('s.company_id', $this->cid)->where('s.property_id', $p->id)
                ->orderBy('u.name')->get(['s.user_id', 'u.name'])->map(fn ($r) => ['user_id' => (int) $r->user_id, 'name' => (string) $r->name])->all();
        }

        return ['id' => (int) $p->id, 'code' => $p->code, 'name' => (string) $p->name, 'kind' => (string) $p->kind, 'address' => $p->address, 'area' => $p->area,
            'district' => $p->district, 'lat' => $p->lat === null ? null : (float) $p->lat, 'lng' => $p->lng === null ? null : (float) $p->lng, 'photo_url' => $this->fileUrl($p->photo_uuid),
            'landlord' => $this->ref('landlord', $p->landlord_id),
            'commission' => $p->commission_type === null || $this->tied ? null : self::commission($p->commission_type, $p->commission_bp, $p->commission_minor),
            'deposit_held_by' => $p->deposit_held_by, 'water_rate_minor' => (int) $p->water_rate_minor, 'power_rate_minor' => (int) $p->power_rate_minor, 'status' => (string) $p->status,
            'units_count' => (int) ($figures['units_count'] ?? 0), 'occupied_count' => (int) ($figures['occupied_count'] ?? 0), 'vacant_count' => (int) ($figures['vacant_count'] ?? 0),
            'occupancy_pct' => (float) ($figures['occupancy_pct'] ?? 0), 'arrears_minor' => (int) ($figures['arrears_minor'] ?? 0), 'staff' => $team, 'notes' => $staff ? $p->notes : null];
    }

    /** Unit, with its state (Units::state) and its live lease. @param array<string, mixed>|null $position */
    public function unit(RentalUnit $u, ?RentalLease $lease = null, ?array $position = null, bool $phones = true): array
    {
        if ($lease !== null && $lease->isLive()) {
            $position ??= $this->position($lease);
        } else {
            $lease = null;
            $position = null;
        }

        return ['id' => (int) $u->id, 'property' => $this->ref('property', $u->property_id), 'code' => (string) $u->code, 'kind' => (string) $u->kind, 'floor' => $u->floor,
            'block' => $u->block, 'bedrooms' => $u->bedrooms, 'bathrooms' => $u->bathrooms, 'size_sqm' => $u->size_sqm, 'rent_minor' => (int) $u->rent_minor,
            'deposit_minor' => $u->deposit_minor === null ? null : (int) $u->deposit_minor, 'cycle_months' => (int) $u->cycle_months, 'status' => (string) $u->status, 'in_use_by' => $u->in_use_by,
            'state' => Units::state($u, $lease, $position), 'water_meter' => $u->water_meter, 'power_meter' => $u->power_meter, 'notes' => $u->notes,
            'photo_url' => $this->fileUrl($u->photo_uuid), 'lease' => $lease !== null ? $this->leaseBrief($lease, $position, $phones) : null];
    }

    /**
     * Tenant: the ID number masked (Directory::masked), what they owe and have paid ahead, their leases. $own: the
     * tenant's own view (no staff notes).
     *
     * @param  array{owed_minor: int, credit_minor: int, leases: list<array{lease: RentalLease, position: array}>}  $figures  Figures::tenant
     */
    public function tenant(RentalTenant $t, array $figures, bool $hasLogin, bool $own = false): array
    {
        $leases = array_map(fn ($row) => $row['lease'], $figures['leases']);
        $this->prime($leases);

        return ['id' => (int) $t->id, 'code' => $t->code, 'kind' => (string) $t->kind, 'name' => (string) $t->name, 'phone' => $t->phone, 'alt_phone' => $t->alt_phone,
            'email' => $t->email, 'id_type' => $t->id_type, 'id_number_masked' => Directory::masked($t->getAttribute('id_number')), 'occupation' => $t->occupation,
            'employer' => $t->employer, 'next_of_kin_name' => $t->next_of_kin_name, 'next_of_kin_phone' => $t->next_of_kin_phone, 'photo_url' => $this->fileUrl($t->photo_uuid),
            'notes' => $own ? null : $t->notes, 'status' => (string) $t->status, 'owed_minor' => (int) $figures['owed_minor'], 'credit_minor' => (int) $figures['credit_minor'],
            'has_login' => $hasLogin, 'leases' => array_map(fn ($row) => $this->leaseBrief($row['lease'], $row['position']), $figures['leases'])];
    }

    public function payout(RentalPayout $p): array
    {
        return ['id' => (int) $p->id, 'number' => (string) $p->number, 'landlord' => $this->ref('landlord', $p->landlord_id), 'amount_minor' => (int) $p->amount_minor,
            'method' => (string) $p->method, 'reference' => $p->reference, 'paid_on' => self::day($p->paid_on), 'period_from' => self::day($p->period_from),
            'period_to' => self::day($p->period_to), 'notes' => $p->notes, 'advance_minor' => (int) $p->advance_minor, 'advance_reason' => $p->advance_reason, 'reversed' => $p->reversed_at !== null];
    }

    public function expense(RentalExpense $e): array
    {
        return ['id' => (int) $e->id, 'number' => (string) $e->number, 'property' => $this->ref('property', $e->property_id), 'unit' => $this->ref('unit', $e->unit_id),
            'landlord' => $this->ref('landlord', $e->landlord_id), 'category' => (string) $e->category, 'description' => $e->description, 'vendor' => $e->vendor,
            'amount_minor' => (int) $e->amount_minor, 'method' => (string) $e->method, 'paid_on' => self::day($e->paid_on), 'charged_to' => (string) $e->charged_to,
            'receipt_url' => $this->fileUrl($e->receipt_uuid), 'maintenance_id' => $e->maintenance_id === null ? null : (int) $e->maintenance_id, 'reversed' => $e->reversed_at !== null];
    }

    /** Maintenance. A tenant's own view leaves out the cost, the expense and which staff member has it. */
    public function maintenance(RentalMaintenance $m, bool $forTenant = false): array
    {
        $photos = array_values(array_filter((array) ($m->photos ?? []), fn ($u) => is_string($u) && $u !== ''));
        $this->primeFiles($photos);

        return ['id' => (int) $m->id, 'uuid' => (string) $m->uuid, 'number' => (string) $m->number, 'property' => $this->ref('property', $m->property_id),
            'unit' => $this->ref('unit', $m->unit_id), 'tenant' => $this->tenantRef($m->tenant_id, false), 'title' => (string) $m->title, 'details' => $m->details,
            'priority' => (string) $m->priority, 'status' => (string) $m->status, 'reported_by' => (string) $m->reported_by,
            'assigned_to' => $forTenant ? null : $this->ref('user', $m->assigned_to_id), 'assignee_name' => $forTenant ? null : $m->assignee_name,
            'photos' => array_map(fn ($u) => ['uuid' => $u, 'url' => $this->files[$u] ?? null], $photos),
            'cost_minor' => $forTenant || $m->cost_minor === null ? null : (int) $m->cost_minor, 'expense_id' => $forTenant || $m->expense_id === null ? null : (int) $m->expense_id,
            'resolved_on' => self::day($m->resolved_on), 'resolution' => $m->resolution, 'created_at' => $m->created_at?->toIso8601String()];
    }

    public function meterReading(RentalMeterReading $r): array
    {
        return ['id' => (int) $r->id, 'uuid' => (string) $r->uuid, 'unit' => $this->ref('unit', $r->unit_id), 'utility' => (string) $r->utility, 'reading' => (float) $r->reading,
            'previous' => (float) $r->previous, 'used' => (float) $r->used, 'rate_minor' => (int) $r->rate_minor, 'amount_minor' => (int) $r->amount_minor,
            'read_on' => self::day($r->read_on), 'reset' => (bool) $r->reset, 'charge_id' => $r->charge_id === null ? null : (int) $r->charge_id, 'photo_url' => $this->fileUrl($r->photo_uuid)];
    }

    public function followUp(RentalFollowUp $f): array
    {
        return ['id' => (int) $f->id, 'uuid' => $f->getAttribute('uuid'), 'channel' => (string) $f->channel, 'note' => $f->note, 'owed_minor' => (int) $f->owed_minor, 'promise_on' => self::day($f->promise_on),
            'by' => $this->ref('user', $f->created_by_id), 'created_at' => $f->created_at?->toIso8601String()];
    }

    /** The account's settings, with the lists the screens choose from. */
    public function settings(RentalSetting $s): array
    {
        $out = [];
        foreach (RentalsModule::WRITABLE as $k) {
            $out[$k] = $s->getAttribute($k);
        }
        $out['messages'] = (object) ((array) ($s->messages ?? []));

        return $out + ['setup_at' => $s->setup_at?->toIso8601String(), 'set_up' => $s->setup_at !== null, 'closed_through' => RentalLedger::closedThrough((int) $s->company_id), 'lists' => [
            'currencies' => array_values(array_unique([...(array) config('saas.currencies'), (string) $s->currency])), 'message_tags' => RentalMessages::TAGS,
            'unit_kinds' => (array) config('rentals.unit_kinds'), 'property_kinds' => (array) config('rentals.property_kinds'), 'methods' => (array) config('rentals.methods'),
            'cycles' => (array) config('rentals.cycles'), 'item_presets' => array_values((array) config('rentals.item_presets')), 'expense_categories' => Expenses::categories(),
            'message_kinds' => RentalMessages::KINDS, 'message_defaults' => RentalMessages::DEFAULTS, 'placeholders' => RentalMessages::PLACEHOLDERS,
        ]];
    }

    /** One line of the audit trail (its meta never holds an ID number or a full phone number: RentalAudit). */
    public function activity(RentalActivity $a): array
    {
        $actor = match ($a->actor_type) {
            'staff' => $this->ref('user', $a->actor_id),
            'tenant' => $this->ref('tenant', $a->actor_id),
            'landlord' => $this->ref('landlord', $a->actor_id),
            default => null,
        };

        return ['id' => (int) $a->id, 'actor_type' => (string) $a->actor_type, 'actor' => $actor, 'action' => (string) $a->action, 'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id === null ? null : (int) $a->subject_id, 'summary' => (string) $a->summary, 'meta' => $a->meta, 'created_at' => $a->created_at?->toIso8601String()];
    }

    /** {type, bp, minor}. */
    public static function commission(?string $type, mixed $bp, mixed $minor): array
    {
        return ['type' => (string) ($type ?: 'none'), 'bp' => (int) $bp, 'minor' => (int) $minor];
    }

    public static function day(mixed $d): ?string
    {
        if ($d === null || $d === '') {
            return null;
        }

        return $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10);
    }

    private static function str(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : $v;
    }

    private function row(string $kind, mixed $id): ?object
    {
        if (! $id) {
            return null;
        }
        $id = (int) $id;
        if (! array_key_exists($id, $this->names[$kind] ?? [])) {
            $this->load($kind, [$id]);
        }

        return $this->names[$kind][$id] ?? null;
    }

    /** @param list<int> $ids */
    private function load(string $kind, array $ids): void
    {
        $ids = array_values(array_filter($ids, fn ($id) => ! array_key_exists($id, $this->names[$kind] ?? [])));
        if ($ids === []) {
            return;
        }
        [$table, $cols] = self::LOOKUPS[$kind];
        $rows = DB::table($table)->where('company_id', $this->cid)->whereIn('id', $ids)->get($cols)->keyBy('id');
        foreach ($ids as $id) {
            $this->names[$kind][$id] = $rows->get($id);
        }
    }
}

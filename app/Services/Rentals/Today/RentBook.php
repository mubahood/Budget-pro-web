<?php

namespace App\Services\Rentals\Today;

use App\Models\RentalFollowUp;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Facades\DB;

/**
 * The offline book of a phone (`GET rentals/rent-book`): every door, lease, balance and tenant this person may see, as one document. A caretaker's holds only
 * their properties. Compact and for reading: balances come from Leases::positions, and a phone never works one out for itself.
 *
 * It holds no ID number, note, commission, or landlord's balance. Its reads do not grow with the number of doors. Every id in it resolves inside it.
 */
final class RentBook
{
    public function __construct(private readonly User $by) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $cid = (int) $this->by->company_id;
        $settings = RentalsModule::settings($cid);
        $today = LocalDate::today($cid);
        $owner = $settings->mode === 'owner';

        $leases = RentalAccess::scope(RentalLease::withoutGlobalScopes()->where('company_id', $cid), $this->by)
            ->where(fn ($q) => $q->whereIn('status', RentalLease::LIVE)->orWhere(fn ($ended) => $ended->where('status', 'ended')->where(fn ($money) => $money
                ->whereIn('id', DB::table('rental_charges')->where('company_id', $cid)->where('status', 'open')->where('kind', '!=', 'refund')->select('lease_id'))
                ->orWhereIn('id', DB::table('rental_payments')->where('company_id', $cid)->whereNull('reversed_at')->where('unallocated_minor', '>', 0)->select('lease_id')))))
            ->orderBy('id')->get();
        $positions = Leases::positions($leases->all(), $today);
        $leaseIds = $leases->map(fn (RentalLease $l) => (int) $l->id)->all();
        $liveByUnit = [];
        foreach ($leases as $l) {
            if ($l->isLive()) {
                $liveByUnit[(int) $l->unit_id] = $l;
            }
        }

        $unitIds = $leases->map(fn (RentalLease $l) => (int) $l->unit_id)->unique()->values()->all();
        $units = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->where(function ($q) use ($unitIds, $cid) {
            $q->whereIn('id', $unitIds ?: [0])->orWhere(fn ($open) => RentalAccess::scope($open->where('status', '!=', 'inactive')
                ->whereIn('property_id', DB::table('rental_properties')->where('company_id', $cid)->where('status', 'active')->select('id')), $this->by));
        })->orderBy('property_id')->orderBy('id')->get();

        $propertyIds = $units->map(fn ($u) => (int) $u->property_id)->merge($leases->map(fn (RentalLease $l) => (int) $l->property_id))->unique()->values()->all();
        $properties = RentalAccess::scope(DB::table('rental_properties')->where('company_id', $cid), $this->by, 'id')
            ->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $propertyIds ?: [0]))->orderBy('name')->orderBy('id')
            ->get(['id', 'code', 'name', 'area', 'landlord_id', 'status']);
        $seen = $properties->pluck('id')->map(fn ($v) => (int) $v)->all();
        $counts = $this->counts($cid, $seen);

        $tenantIds = $leases->map(fn (RentalLease $l) => (int) $l->tenant_id)->unique()->values()->all();
        $tenants = DB::table('rental_tenants')->where('company_id', $cid)->whereIn('id', $tenantIds ?: [0])->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'phone', 'phone_e164', 'alt_phone']);
        $landlords = $owner ? collect() : DB::table('rental_landlords')->where('company_id', $cid)->where('is_self', false)->whereIn('id', $properties->pluck('landlord_id')->filter()->unique()->values()->all() ?: [0])
            ->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'phone', 'phone_e164']);

        $lastPaid = $leaseIds === [] ? [] : DB::table('rental_payments')->where('company_id', $cid)->whereIn('lease_id', $leaseIds)->whereNull('reversed_at')
            ->whereIn('method', [...RentalPayment::CASH_METHODS, RentalPayment::DIRECT])->groupBy('lease_id')->selectRaw('lease_id, MAX(paid_on) AS paid_on')->pluck('paid_on', 'lease_id')->all();
        $lastFollowUp = $this->lastFollowUps($cid, $leaseIds);

        return [
            'generated_at' => now()->toIso8601String(), 'today' => $today->toDateString(), 'currency' => (string) $settings->currency, 'decimals' => Minor::decimals($cid),
            'grace_days' => (int) $settings->grace_days, 'mode' => (string) $settings->mode,
            'properties' => $properties->map(fn ($p) => ['id' => (int) $p->id, 'code' => $p->code, 'name' => (string) $p->name, 'area' => $p->area, 'landlord_id' => $owner || $p->landlord_id === null ? null : (int) $p->landlord_id, 'status' => (string) $p->status,
                'units_count' => (int) ($counts['units'][(int) $p->id] ?? 0), 'occupied_count' => (int) ($counts['occupied'][(int) $p->id] ?? 0)])->values()->all(),
            'units' => $units->map(function ($u) use ($liveByUnit, $positions) {
                $lease = $liveByUnit[(int) $u->id] ?? null;

                return ['id' => (int) $u->id, 'property_id' => (int) $u->property_id, 'code' => (string) $u->code, 'floor' => $u->floor, 'block' => $u->block, 'kind' => (string) $u->kind,
                    'rent_minor' => (int) $u->rent_minor, 'deposit_minor' => $u->deposit_minor === null ? null : (int) $u->deposit_minor, 'status' => (string) $u->status,
                    'state' => Units::state($u, $lease, $lease !== null ? ($positions[(int) $lease->id] ?? null) : null), 'lease_id' => $lease !== null ? (int) $lease->id : null];
            })->values()->all(),
            'leases' => $leases->map(fn (RentalLease $l) => ['id' => (int) $l->id, 'number' => (string) $l->number, 'unit_id' => (int) $l->unit_id, 'property_id' => (int) $l->property_id, 'tenant_id' => (int) $l->tenant_id,
                'rent_minor' => (int) $l->rent_minor, 'cycle_months' => (int) $l->cycle_months, 'status' => (string) $l->status, 'start_date' => $l->start_date->toDateString(), 'end_date' => $l->end_date?->toDateString(),
                'notice_on' => $l->notice_on?->toDateString(), 'move_out_on' => $l->move_out_on?->toDateString(), 'position' => $positions[(int) $l->id] ?? null,
                'last_paid_on' => isset($lastPaid[(int) $l->id]) ? substr((string) $lastPaid[(int) $l->id], 0, 10) : null, 'last_follow_up' => $lastFollowUp[(int) $l->id] ?? null])->values()->all(),
            'tenants' => $tenants->map(fn ($t) => ['id' => (int) $t->id, 'code' => $t->code, 'name' => (string) $t->name, 'phone' => $t->phone, 'phone_e164' => $t->phone_e164, 'alt_phone' => $t->alt_phone])->values()->all(),
            'landlords' => $landlords->map(fn ($l) => ['id' => (int) $l->id, 'code' => $l->code, 'name' => (string) $l->name, 'phone' => $l->phone, 'phone_e164' => $l->phone_e164])->values()->all(),
        ];
    }

    /**
     * The ETag of a book: everything in it but the moment it was read, so it changes only when what a phone would show does.
     *
     * @param  array<string, mixed>  $book
     */
    public static function etag(array $book): string
    {
        unset($book['generated_at']);

        return '"'.sha1((string) json_encode($book)).'"';
    }

    /** Whether a request's If-None-Match names this ETag. A web server's compression may have weakened or marked the one the phone kept, so those still match. */
    public static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }
        $plain = trim($etag, '"');
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $c = (string) preg_replace('/^W\//', '', trim($candidate));
            $c = trim($c, '"');
            if ($c === $plain || $c === $plain.'-gzip' || $c === $plain.'-br') {
                return true;
            }
        }

        return false;
    }

    /**
     * Units for letting and those let, per property: the rule of Figures::properties, without its arrears.
     *
     * @param  list<int>  $propertyIds
     * @return array{units: array<int, int>, occupied: array<int, int>}
     */
    private function counts(int $cid, array $propertyIds): array
    {
        if ($propertyIds === []) {
            return ['units' => [], 'occupied' => []];
        }

        return [
            'units' => DB::table('rental_units')->where('company_id', $cid)->whereIn('property_id', $propertyIds)->whereNotIn('status', ['inactive', 'in_use'])
                ->groupBy('property_id')->selectRaw('property_id, COUNT(*) AS n')->pluck('n', 'property_id')->all(),
            'occupied' => DB::table('rental_leases')->where('company_id', $cid)->whereIn('property_id', $propertyIds)->whereIn('status', RentalLease::LIVE)
                ->groupBy('property_id')->selectRaw('property_id, COUNT(DISTINCT unit_id) AS n')->pluck('n', 'property_id')->all(),
        ];
    }

    /**
     * @param  list<int>  $leaseIds
     * @return array<int, array{channel: string, note: ?string, promise_on: ?string, at: ?string}>
     */
    private function lastFollowUps(int $cid, array $leaseIds): array
    {
        if ($leaseIds === []) {
            return [];
        }
        $latest = RentalFollowUp::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $leaseIds)->groupBy('lease_id')->selectRaw('MAX(id) AS id')->pluck('id')->all();
        $out = [];
        foreach (RentalFollowUp::withoutGlobalScopes()->whereIn('id', $latest ?: [0])->get() as $f) {
            $out[(int) $f->lease_id] = ['channel' => (string) $f->channel, 'note' => $f->note, 'promise_on' => $f->promise_on?->toDateString(), 'at' => $f->created_at?->toIso8601String()];
        }

        return $out;
    }
}

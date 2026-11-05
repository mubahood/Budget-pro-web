<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalCharge;
use App\Models\RentalLease;
use App\Models\RentalProperty;
use App\Models\RentalUnit;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalsModule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Units: rooms, flats, houses and shops. A unit's state (vacant, occupied, overdue, notice, reserved, maintenance,
 * inactive) is Directory\Units::state, from its live lease; the `state` filter picks units in that state.
 */
class UnitsController extends RentalsController
{
    public const STATES = ['vacant', 'occupied', 'overdue', 'notice', 'reserved', 'maintenance', 'inactive', 'in_use', 'listed', 'let', 'not_for_letting'];

    /**
     * GET rentals/units?property_id&landlord_id&state=vacant|occupied|overdue|notice|…&status&kind&q: besides a unit's own state, as the web's list: `listed` (all but switched off), `let` (any live lease, overdue or not),
     * `not_for_letting` (reserved, under repair or in use). `q` also finds the property, and a live tenant's name or phone. `meta.totals`: {count, let, rent_minor, owed_minor} of the whole list.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $q = $this->scoped(RentalUnit::withoutGlobalScopes()->where('company_id', $cid), $request);
            if ($request->filled('property_id')) {
                $q->where('property_id', (int) $request->query('property_id'));
            }
            if ($request->filled('landlord_id')) {
                $q->whereIn('property_id', RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', (int) $request->query('landlord_id'))->select('id'));
            }
            if (in_array($request->query('status'), ['available', 'reserved', 'maintenance', 'inactive', 'in_use'], true)) {
                $q->where('status', $request->query('status'));
            }
            if ($request->filled('kind')) {
                $q->where('kind', (string) $request->query('kind'));
            }
            if (in_array($state = (string) $request->query('state', ''), self::STATES, true)) {
                $this->inState($request, $q, $state, $cid);
            }
            if ($state === 'vacant') { // a door to let is in a property still in the lists, as the web's move-in offers them
                $q->whereIn('property_id', RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'active')->select('id'));
            }
            if (($term = trim((string) $request->query('q', ''))) !== '') {
                $like = self::like($term);
                $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('block', 'like', $like)->orWhere('floor', 'like', $like)
                    ->orWhereIn('property_id', RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->where('name', 'like', $like)->select('id'))
                    ->orWhereIn('id', RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', RentalLease::LIVE)->whereIn('tenant_id', self::tenantsLike($cid, $term))->select('unit_id')));
            }
            $liveLeases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', RentalLease::LIVE)->whereIn('unit_id', (clone $q)->select('id'));
            $let = (clone $liveLeases)->count();
            $rent = (int) (clone $liveLeases)->sum('rent_minor');
            $owed = (int) RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', (clone $liveLeases)->select('id'))->where('status', 'open')->where('kind', '!=', 'refund')
                ->sum(DB::raw('GREATEST(amount_minor - paid_minor, 0)'));
            $p = $this->paginate($q->orderBy('property_id')->orderByRaw('LENGTH(code), code')->orderBy('id'), $request, 100);
            $units = collect($p->items());
            $leases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('unit_id', $units->pluck('id')->all() ?: [0])
                ->whereIn('status', RentalLease::LIVE)->orderByDesc('id')->get()->unique('unit_id')->keyBy('unit_id');
            $present = $this->present($request)->prime($units)->prime($leases->values())->primePositions($leases->values())->primeFiles($units->pluck('photo_uuid'));

            return $this->page($p, $units->map(fn ($u) => $present->unit($u, $leases->get($u->id)))->all(), ['totals' => ['count' => $p->total(), 'let' => $let, 'rent_minor' => $rent, 'owed_minor' => $owed]]);
        });
    }

    /** POST rentals/units {property_id, code, kind, floor, block, bedrooms, bathrooms, size_sqm, rent_minor, deposit_minor, cycle_months, status, water_meter, power_meter, notes, photo_uuid} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->created($this->shape($request, app(Units::class)->create($this->user($request), $request->only(Units::WRITABLE))), 'Unit added.'));
    }

    /** GET rentals/units/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->shape($request, app(Units::class)->find($this->user($request), $id))));
    }

    /** PUT rentals/units/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $unit = app(Units::class)->find($this->user($request), $id);

            return $this->success($this->shape($request, app(Units::class)->update($this->user($request), $unit, $request->only(Units::WRITABLE))), 'Saved.');
        });
    }

    /**
     * Units in a state, by the same rules as Units::state: occupied / overdue / notice from the live lease (overdue: a
     * bill open past its due date and the grace days, as in Leases::position), the rest from the unit's own status.
     */
    private function inState(Request $request, Builder $q, string $state, int $cid): void
    {
        $live = fn (array $statuses) => fn ($l) => $l->from('rental_leases')->whereColumn('rental_leases.unit_id', 'rental_units.id')->where('rental_leases.company_id', $cid)
            ->whereIn('rental_leases.status', $statuses);
        $cutoff = $this->present($request)->today()->subDays((int) $this->present($request)->settingsRow()->grace_days)->toDateString();
        $overdue = fn ($l) => $l->from('rental_leases')->whereColumn('rental_leases.unit_id', 'rental_units.id')->where('rental_leases.company_id', $cid)
            ->where('rental_leases.status', 'active')->whereExists(fn ($c) => $c->from('rental_charges')->whereColumn('rental_charges.lease_id', 'rental_leases.id')
                ->where('rental_charges.status', 'open')->where('rental_charges.kind', '!=', 'refund')->where('rental_charges.due_date', '<', $cutoff));
        match ($state) {
            'notice' => $q->whereExists($live(['notice'])),
            'overdue' => $q->whereExists($overdue),
            'occupied' => $q->whereExists($live(['active']))->whereNotExists($overdue),
            'vacant' => $q->whereNotExists($live(RentalLease::LIVE))->whereNotIn('status', ['reserved', 'maintenance', 'inactive', 'in_use']),
            'listed' => $q->where('status', '!=', 'inactive'),
            'let' => $q->whereExists($live(RentalLease::LIVE)),
            'not_for_letting' => $q->whereNotExists($live(RentalLease::LIVE))->whereIn('status', ['reserved', 'maintenance', 'in_use']),
            default => $q->whereNotExists($live(RentalLease::LIVE))->where('status', $state),
        };
    }

    private function shape(Request $request, RentalUnit $u): array
    {
        $lease = RentalLease::withoutGlobalScopes()->where('company_id', $u->company_id)->where('unit_id', $u->id)->whereIn('status', RentalLease::LIVE)->orderByDesc('id')->first();

        // The unit's own commission (null: it follows its property), for those who may change it. Present::unit is also a landlord's view of a unit, so it stays out of there.
        $rule = RentalAccess::can($this->user($request), 'rentals_manage') && ! RentalsModule::isOwnerMode((int) $u->company_id)
            ? ['commission' => $u->commission_type === null ? null : Present::commission($u->commission_type, $u->commission_bp, $u->commission_minor)] : [];

        return $this->present($request)->unit($u, $lease) + $rule;
    }
}

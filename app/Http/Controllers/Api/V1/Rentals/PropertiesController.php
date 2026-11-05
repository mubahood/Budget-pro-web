<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalProperty;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Landlords\Handover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Properties: buildings, estates and plots, with their occupancy, arrears and who looks after them. */
class PropertiesController extends RentalsController
{
    /** GET rentals/properties?q&status&landlord_id */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $q = $this->scoped(RentalProperty::withoutGlobalScopes()->where('company_id', $cid), $request, 'id');
            if (in_array($request->query('status'), ['active', 'archived'], true)) {
                $q->where('status', $request->query('status'));
            }
            if ($request->filled('landlord_id')) {
                $q->where('landlord_id', (int) $request->query('landlord_id'));
            }
            self::search($q, $request->query('q'), ['name', 'code', 'address', 'area', 'district']);
            $p = $this->paginate($q->orderBy('name')->orderBy('id'), $request);
            $figures = Figures::properties($cid, collect($p->items())->pluck('id')->map(fn ($x) => (int) $x)->all(), $this->user($request));
            $present = $this->present($request)->prime($p->items())->primeFiles(collect($p->items())->pluck('photo_uuid'));

            return $this->page($p, collect($p->items())->map(fn ($x) => $present->property($x, $figures[(int) $x->id] ?? null))->all());
        });
    }

    /** POST rentals/properties {landlord_id, name, kind, address, area, district, lat, lng, photo_uuid, commission_*, deposit_held_by, water_rate_minor, power_rate_minor, notes} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->created($this->shape($request, app(Properties::class)->create($this->user($request), $request->only(Properties::WRITABLE))), 'Property added.'));
    }

    /** GET rentals/properties/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->shape($request, app(Properties::class)->find($this->user($request), $id))));
    }

    /** PUT rentals/properties/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $property = app(Properties::class)->find($this->user($request), $id);

            return $this->success($this->shape($request, app(Properties::class)->update($this->user($request), $property, $request->only(Properties::WRITABLE))), 'Saved.');
        });
    }

    /**
     * POST rentals/properties/{id}/handover {landlord_id, from, reason}: give it to another landlord (LC-01). No money moves: live leases and unpaid bills from
     * that day go to the new landlord, the rest stays. A day ahead waits for its day. A plain PUT refuses a new landlord once money or a tenant is recorded (use_handover).
     */
    public function handover(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $property = app(Properties::class)->find($this->user($request), $id);
            $this->day($request, 'from');
            app(Handover::class)->handOver($this->user($request), $property, $request->only(['landlord_id', 'from', 'reason']));

            return $this->success($this->shape($request, $property->fresh()), 'Handed over.');
        });
    }

    /** PUT rentals/properties/{id}/staff {user_ids: []}: who looks after the property (a caretaker sees only theirs). */
    public function staff(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $property = app(Properties::class)->find($this->user($request), $id);
            app(Properties::class)->assignStaff($this->user($request), $property, array_values((array) $request->input('user_ids', [])));

            return $this->success($this->shape($request, $property->fresh()), 'Saved.');
        });
    }

    private function shape(Request $request, RentalProperty $p): array
    {
        return $this->present($request)->property($p, Figures::properties($this->cid($request), [(int) $p->id], $this->user($request))[(int) $p->id] ?? null);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalMaintenance;
use App\Models\RentalUnit;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\RentalAccess;
use App\Support\Rentals\Minor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Repairs and problems (rentals_maintenance). Opening one is an offline-safe write: the body's `uuid` makes a resend
 * the same request (200 with the first one); the same uuid for another problem is 409 idempotency_conflict. Photos
 * are `files` uuids (POST files, purpose rental_photo).
 */
class MaintenanceController extends RentalsController
{
    /** GET rentals/maintenance?status&property_id&unit_id&priority&q */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_maintenance');
            $q = $this->scoped(RentalMaintenance::withoutGlobalScopes()->where('company_id', $this->cid($request)), $request);
            $status = array_values(array_intersect(explode(',', (string) $request->query('status', '')), array_keys(Maintenance::STATUSES)));
            if ($status !== []) {
                $q->whereIn('status', $status);
            }
            foreach (['property_id', 'unit_id', 'tenant_id'] as $f) {
                if ($request->filled($f)) {
                    $q->where($f, (int) $request->query($f));
                }
            }
            if (array_key_exists((string) $request->query('priority', ''), Maintenance::PRIORITIES)) {
                $q->where('priority', $request->query('priority'));
            }
            self::search($q, $request->query('q'), ['number', 'title', 'details', 'assignee_name']);
            $p = $this->paginate($q->orderByDesc('id'), $request);
            $present = $this->present($request)->prime($p->items())->primeFiles(collect($p->items())->flatMap(fn ($m) => (array) ($m->photos ?? [])));

            return $this->page($p, collect($p->items())->map(fn ($m) => $present->maintenance($m))->all());
        });
    }

    /** POST rentals/maintenance {uuid, property_id, unit_id, title, details, priority, photos[], assigned_to_id, assignee_name} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $uuid = $request->input('uuid');
            if ($this->fromApp($request) && ($uuid === null || $uuid === '')) {
                throw BusinessRuleException::make('uuid_required', 'Send the request\'s id (uuid) so a resend is never counted twice.');
            }
            if ($uuid !== null && $uuid !== '' && (! is_string($uuid) || ! Str::isUuid($uuid))) {
                throw BusinessRuleException::make('uuid_invalid', 'The request id is not valid.');
            }
            $data = $request->only(['property_id', 'unit_id', 'title', 'details', 'priority', 'photos', 'assigned_to_id', 'assignee_name']) + ['uuid' => $uuid ?: null];
            $existing = $uuid ? RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first() : null;
            if ($existing !== null) {
                RentalAccess::authorize($this->user($request), 'rentals_maintenance');
                $unit = ! empty($data['unit_id']) ? RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->find((int) $data['unit_id']) : null;
                $property = (int) ($unit?->property_id ?? ($data['property_id'] ?? 0));
                if (! RentalAccess::inScope($this->user($request), (int) $existing->property_id) || (int) $existing->property_id !== $property
                    || (int) ($existing->unit_id ?? 0) !== (int) ($unit?->id ?? 0) || trim((string) $existing->title) !== mb_substr(trim((string) ($data['title'] ?? '')), 0, 150)) {
                    throw BusinessRuleException::make('idempotency_conflict', 'This request id was already used for a different problem.');
                }

                return $this->success($this->present($request)->maintenance($existing), 'Already reported.');
            }
            $m = app(Maintenance::class)->open($this->user($request), $cid, array_filter($data, fn ($v) => $v !== null), ['staff', null]);

            return $this->created($this->present($request)->maintenance($m->fresh()), 'Reported.');
        });
    }

    /** GET rentals/maintenance/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            RentalAccess::authorize($this->user($request), 'rentals_maintenance');

            return $this->success($this->present($request)->maintenance($this->find($request, $id)));
        });
    }

    /**
     * PUT rentals/maintenance/{id} {status, priority, title, details, assigned_to_id, assignee_name, photos[]}; with
     * status `done`: {cost_minor, category, vendor, method, paid_on, charged_to, receipt_uuid, resolved_on, resolution, over_limit_reason} (a cost becomes an
     * expense); with status `cancelled`: {reason}.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $m = $this->find($request, $id);
            $svc = app(Maintenance::class);
            $by = $this->user($request);
            $m = match ($request->input('status')) {
                'done' => $svc->complete($by, $m, ['cost_minor' => $request->filled('cost_minor') ? Minor::nonNegative($request->input('cost_minor'), 'cost') : 0,
                    'paid_on' => $this->day($request, 'paid_on'), 'resolved_on' => $this->day($request, 'resolved_on')]
                    + $request->only(['category', 'vendor', 'method', 'charged_to', 'receipt_uuid', 'resolution', 'over_limit_reason'])),
                'cancelled' => $svc->cancel($by, $m, (string) ($request->input('reason') ?? $request->input('resolution') ?? '')),
                default => $svc->update($by, $m, $request->only(['status', 'priority', 'title', 'details', 'assigned_to_id', 'assignee_name', 'photos'])),
            };

            return $this->success($this->present($request)->maintenance($m), 'Saved.');
        });
    }

    private function find(Request $request, int $id): RentalMaintenance
    {
        return $this->scoped(RentalMaintenance::withoutGlobalScopes()->where('company_id', $this->cid($request)), $request)->find($id) ?? throw $this->notFoundRecord('request');
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalMaintenance;
use App\Models\RentalUnit;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Portal\LandlordApprovals;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalsModule;
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
            $this->present($request)->prime($p->items())->primeFiles(collect($p->items())->flatMap(fn ($m) => (array) ($m->photos ?? [])));

            return $this->page($p, collect($p->items())->map(fn ($m) => $this->shape($request, $m))->all());
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

                return $this->success($this->shape($request, $existing), 'Already reported.');
            }
            $m = app(Maintenance::class)->open($this->user($request), $cid, array_filter($data, fn ($v) => $v !== null), ['staff', null]);

            return $this->created($this->shape($request, $m->fresh(), true), 'Reported.');
        });
    }

    /** GET rentals/maintenance/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            RentalAccess::authorize($this->user($request), 'rentals_maintenance');

            return $this->success($this->shape($request, $this->find($request, $id), true));
        });
    }

    /**
     * PUT rentals/maintenance/{id} {status, priority, title, details, assigned_to_id, assignee_name, photos[], quote_minor}; with
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
                default => $this->saveOpen($request, $m),
            };

            return $this->success($this->shape($request, $m, true), 'Saved.');
        });
    }

    /** POST rentals/maintenance/{id}/ask-landlord: it shows on the landlord's page as waiting for their answer; answers with the message for this person's own phone. */
    public function askLandlord(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $by = $this->user($request);
            $m = app(LandlordApprovals::class)->ask($by, $this->find($request, $id));
            $base = config('rentals.portal_url');
            $link = is_string($base) && $base !== '' ? rtrim($base, '/').'/my/repairs' : null;

            return $this->success(['maintenance' => $this->shape($request, $m, true), 'message' => LandlordApprovals::message($by, $m, $link)], 'Asked.');
        });
    }

    /** POST rentals/maintenance/{id}/landlord-answer {yes, via: phone|whatsapp|other, who, note}: an answer the office had another way. */
    public function landlordAnswer(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $ticket = $this->find($request, $id);
            $request->validate(['yes' => ['required', 'boolean']]);
            $m = app(LandlordApprovals::class)->record($this->user($request), $ticket, $request->boolean('yes'), (string) $request->input('via', ''), (string) $request->input('who', ''),
                $request->filled('note') ? (string) $request->input('note') : null);

            return $this->success($this->shape($request, $m, true), 'Saved.');
        });
    }

    /** A plain save of a request still open, and a new quote if one came: a new figure is a new question for the landlord (Maintenance::quote). */
    private function saveOpen(Request $request, RentalMaintenance $m): RentalMaintenance
    {
        $svc = app(Maintenance::class);
        $by = $this->user($request);
        $fields = $request->only(['status', 'priority', 'title', 'details', 'assigned_to_id', 'assignee_name', 'photos']);
        // The quote is read first: a bad figure must not leave the rest of the request saved.
        $quote = $request->exists('quote_minor') && $request->input('quote_minor') !== null ? $this->minor($request, 'quote_minor', 'quote') : null;
        if ($fields !== [] || ! $request->exists('quote_minor')) {
            $m = $svc->update($by, $m, $fields);
        }
        if ($request->exists('quote_minor')) {
            $m = $svc->quote($by, $m, $quote);
        }

        return $m;
    }

    /**
     * Maintenance (§9.1) with its quote, and for the office the landlord's limit and answer: those are between the office and the landlord, so a login tied to some properties
     * gets none of them (EL-20). $limit also reads the limit of a request with no quote yet (a few reads, so one record's screen, not a list).
     *
     * @return array<string, mixed>
     */
    private function shape(Request $request, RentalMaintenance $m, bool $limit = false): array
    {
        $by = $this->user($request);
        $out = $this->present($request)->maintenance($m) + ['quote_minor' => $m->quote_minor === null ? null : (int) $m->quote_minor];
        if (! $request->attributes->has('rentals_office')) { // asked once a request: a list asks it for every row
            $request->attributes->set('rentals_office', RentalAccess::can($by, 'rentals_landlords') && RentalAccess::propertyIds($by) === null && ! RentalsModule::isOwnerMode((int) $m->company_id));
        }
        if (! $request->attributes->get('rentals_office')) {
            return $out;
        }
        $check = $limit || $m->quote_minor !== null ? LandlordApprovals::check((int) $m->company_id, (int) $m->property_id, (int) $m->quote_minor, (int) $m->id) : null;

        return $out + ['approval' => ['status' => (string) ($m->approval_status ?? 'none'), 'via' => $m->approval_via, 'note' => $m->approval_note, 'at' => $m->approval_at?->toIso8601String()],
            'limit_minor' => $check['limit_minor'] ?? null, 'needs_answer' => $check !== null && LandlordApprovals::needsAnswer($m)];
    }

    private function find(Request $request, int $id): RentalMaintenance
    {
        return $this->scoped(RentalMaintenance::withoutGlobalScopes()->where('company_id', $this->cid($request)), $request)->find($id) ?? throw $this->notFoundRecord('request');
    }
}

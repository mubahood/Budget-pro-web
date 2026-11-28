<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Models\KinEvent;
use App\Models\KinEventPerson;
use App\Services\Kin\KinEvents;
use App\Services\Sacco\Dues\DuesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gatherings (doc 13.2). Everyone reads and answers for themself (rsvp); kin_events (the head and the secretary) plans,
 * keeps the register, writes the minutes and cancels, as ApiPermissionMap says.
 */
class EventsController extends KinController
{
    public function __construct(private KinEvents $events) {}

    /** GET kin/events?when=upcoming|past (upcoming: not yet held, from today on, soonest first; past: the rest, newest first) */
    public function index(Request $request): JsonResponse
    {
        $when = $request->query('when') === 'past' ? 'past' : 'upcoming';
        $today = now()->startOfDay();
        $q = KinEvent::withoutGlobalScopes()->where('company_id', $this->cid($request));
        if ($when === 'upcoming') {
            $q->where('status', '!=', 'held')->where('starts_at', '>=', $today)->orderBy('starts_at')->orderBy('id');
        } else {
            $q->where(fn ($w) => $w->where('status', 'held')->orWhere('starts_at', '<', $today))->orderByDesc('starts_at')->orderByDesc('id');
        }
        $rows = $q->limit(200)->get();

        return $this->success(['events' => $this->events->list($rows, $this->meId($request))], 'Listed successfully.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->events->detail(KinEvents::find($this->cid($request), $id), $this->meId($request))));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(KinEvents::rules(true));

        return $this->attempt(function () use ($request, $d) {
            $e = $this->events->create($this->company($request), $d, $request->user());

            return $this->created($this->events->detail($e, $this->meId($request)), 'Gathering planned.');
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(KinEvents::rules(false));

        return $this->attempt(fn () => $this->success($this->events->detail($this->events->update(KinEvents::find($this->cid($request), $id), $d), $this->meId($request)), 'Saved.'));
    }

    /** POST kin/events/{id}/rsvp {rsvp}: the sign-in's own person only. */
    public function rsvp(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['rsvp' => ['required', Rule::in(KinEventPerson::RSVPS)]]);

        return $this->attempt(function () use ($request, $id, $d) {
            $e = KinEvents::find($this->cid($request), $id);
            $this->events->rsvp($e, $request->user(), $d['rsvp']);

            return $this->success($this->events->list(collect([$e->fresh()]), $this->meId($request))[0], 'Thank you for answering.');
        });
    }

    /** POST kin/events/{id}/attendance {lines: [{member_id, attendance}]} */
    public function attendance(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['lines' => ['required', 'array', 'min:1', 'max:1000'], 'lines.*.member_id' => ['required', 'integer'],
            'lines.*.attendance' => ['required', Rule::in(KinEventPerson::ATTENDANCE)]]);

        return $this->attempt(function () use ($request, $id, $d) {
            $e = KinEvents::find($this->cid($request), $id);
            $n = $this->events->attendance($e, $d['lines']);

            return $this->success($this->events->detail($e->fresh(), $this->meId($request)), $n === 1 ? 'Register saved for 1 person.' : "Register saved for {$n} people.");
        });
    }

    /** PUT kin/events/{id}/minutes {minutes}: the gathering is then held. */
    public function minutes(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['minutes' => ['required', 'string', 'max:60000']]);

        return $this->attempt(fn () => $this->success($this->events->detail($this->events->minutes(KinEvents::find($this->cid($request), $id), $d['minutes'], $request->user()),
            $this->meId($request)), 'Minutes saved.'));
    }

    /** POST kin/events/{id}/cancel {reason} */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->success($this->events->detail($this->events->cancel(KinEvents::find($this->cid($request), $id), $d['reason']),
            $this->meId($request)), 'Gathering cancelled.'));
    }

    private function meId(Request $request): ?int
    {
        $me = DuesService::personOf($request->user(), $this->cid($request));

        return $me ? (int) $me->id : null;
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalFollowUp;
use App\Models\RentalLease;
use App\Models\RentalTenant;
use App\Services\Rentals\Collections\Arrears;
use App\Services\Rentals\Collections\FollowUps;
use App\Services\Rentals\Messages\RentalMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Collections: overdue leases (aged), the follow-up log with promise dates, and reminder texts with their WhatsApp and SMS links. */
class ArrearsController extends RentalsController
{
    /**
     * GET rentals/arrears?property_id&bucket=d0_30|d31_60|d61_90|d90_plus&sort=amount|days&min_days: each row a LeaseBrief with
     * what is overdue (overdue_minor), for how long (days, bucket), the last follow-up and the promise date.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $filters = array_filter(['property_id' => $request->filled('property_id') ? (int) $request->query('property_id') : null,
                'min_days' => $request->filled('min_days') ? (int) $request->query('min_days') : null,
                'sort' => in_array($request->query('sort'), ['amount', 'days'], true) ? $request->query('sort') : null], fn ($v) => $v !== null);
            $rows = Arrears::list($cid, $this->user($request), $filters);
            if (array_key_exists($bucket = (string) $request->query('bucket', ''), Arrears::BUCKETS)) {
                $rows = array_values(array_filter($rows, fn ($r) => $r['bucket'] === $bucket));
            }
            $aging = Arrears::aging($cid, $this->user($request));
            $p = $this->paginateArray($rows, $request, 100);
            $ids = array_column($p->items(), 'lease_id');
            $leases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $ids ?: [0])->get()->keyBy('id');
            $last = RentalFollowUp::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $ids ?: [0])->orderByDesc('id')->get()->unique('lease_id')->keyBy('lease_id');
            $present = $this->present($request)->prime($leases->values())->prime($last->values());
            $items = [];
            foreach ($p->items() as $r) {
                if (($lease = $leases->get($r['lease_id'])) === null) {
                    continue;
                }
                $f = $last->get($r['lease_id']);
                $items[] = $present->leaseBrief($lease) + ['overdue_minor' => (int) $r['overdue_minor'], 'days' => (int) $r['days'], 'bucket' => $r['bucket'],
                    'last_follow_up' => $f ? $present->followUp($f) : null, 'promise_on' => $f ? Present::day($f->promise_on) : null];
            }

            return $this->page($p, $items, ['aging' => $aging, 'currency' => $present->currency()]);
        });
    }

    /** GET rentals/follow-ups?lease_id&tenant_id&promise_on: newest first. */
    public function followUps(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $q = RentalFollowUp::withoutGlobalScopes()->where('company_id', $cid)
                ->whereIn('lease_id', $this->scoped(RentalLease::withoutGlobalScopes()->where('company_id', $cid), $request)->select('id'));
            if ($request->filled('lease_id')) {
                $q->where('lease_id', (int) $request->query('lease_id'));
            }
            if ($request->filled('tenant_id')) {
                $q->where('tenant_id', (int) $request->query('tenant_id'));
            }
            if (($on = $this->day($request, 'promise_on')) !== null) {
                $q->where('promise_on', $on);
            }
            $p = $this->paginate($q->orderByDesc('id'), $request);
            $present = $this->present($request)->prime($p->items());

            return $this->page($p, collect($p->items())->map(fn ($f) => $present->followUp($f))->all());
        });
    }

    /** POST rentals/follow-ups {lease_id, channel: whatsapp|sms|call|visit|note, note, promise_on} */
    public function logFollowUp(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $lease = $this->lease($request, (int) $request->input('lease_id'));
            $f = app(FollowUps::class)->log($this->user($request), $lease, (string) $request->input('channel', 'note'),
                $request->filled('note') ? (string) $request->input('note') : null, $this->day($request, 'promise_on'));

            return $this->created($this->present($request)->prime([$f])->followUp($f), 'Saved.');
        });
    }

    /**
     * GET rentals/leases/{id}/reminder?kind=bill|due|overdue|notice: the reminder text (the account's own words) and
     * the WhatsApp and SMS links that open it on the user's phone; the server sends nothing (KR-18).
     */
    public function reminder(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $lease = $this->lease($request, $id);
            $r = RentalMessages::reminder($lease);
            $kind = (string) $request->query('kind', '');
            if (in_array($kind, ['bill', 'due', 'overdue', 'notice'], true) && $kind !== $r['kind']) {
                $text = RentalMessages::forLease($lease, $kind);
                $r = ['kind' => $kind, 'text' => $text, 'whatsapp' => RentalMessages::whatsappLink($r['phone'], $text), 'sms' => RentalMessages::smsLink($r['phone'], $text), 'phone' => $r['phone']];
            }
            $tenant = RentalTenant::withoutGlobalScopes()->where('company_id', $lease->company_id)->find($lease->tenant_id);

            return $this->success($r + ['tenant' => $tenant ? ['id' => (int) $tenant->id, 'name' => (string) $tenant->name] : null, 'lease_id' => (int) $lease->id]);
        });
    }
}

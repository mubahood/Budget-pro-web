<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalFollowUp;
use App\Models\RentalLease;
use App\Models\RentalTenant;
use App\Services\Rentals\Collections\Arrears;
use App\Services\Rentals\Collections\FollowUps;
use App\Services\Rentals\Messages\RentalMessages;
use App\Support\Rentals\SearchText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Collections: overdue leases (aged), the follow-up log with promise dates, and reminder texts with their WhatsApp and SMS links. */
class ArrearsController extends RentalsController
{
    /**
     * GET rentals/arrears?property_id&bucket=d0_30|d31_60|d61_90|d90_plus&sort=amount|days&min_days&promise=due&q: each row a LeaseBrief with
     * what is overdue (overdue_minor), for how long (days, bucket), the last follow-up and the promise date. `promise=due`: their promise's day has come. `q`: the tenant's name or phone,
     * the unit, the property, the lease's number. `meta.aging` is the whole list; `meta.totals`, {count, overdue_minor}, is this list as filtered.
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
            if ($request->query('promise') === 'due') {
                $today = $this->present($request)->today()->toDateString();
                $rows = array_values(array_filter($rows, fn ($r) => ($on = $r['last_follow_up']['promise_on'] ?? null) !== null && substr((string) $on, 0, 10) <= $today));
            }
            if (($term = trim((string) $request->query('q', ''))) !== '') {
                $rows = array_values(array_filter($rows, fn ($r) => self::matches($r, $term)));
            }
            $aging = Arrears::aging($cid, $this->user($request));
            $p = $this->paginateArray($rows, $request, 100);
            $ids = array_column($p->items(), 'lease_id');
            $leases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $ids ?: [0])->get()->keyBy('id');
            $last = RentalFollowUp::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $ids ?: [0])->orderByDesc('id')->get()->unique('lease_id')->keyBy('lease_id');
            $present = $this->present($request)->prime($leases->values())->prime($last->values())->primePositions($leases->values());
            $items = [];
            foreach ($p->items() as $r) {
                if (($lease = $leases->get($r['lease_id'])) === null) {
                    continue;
                }
                $f = $last->get($r['lease_id']);
                $items[] = $present->leaseBrief($lease) + ['overdue_minor' => (int) $r['overdue_minor'], 'days' => (int) $r['days'], 'bucket' => $r['bucket'],
                    'last_follow_up' => $f ? $present->followUp($f) : null, 'promise_on' => $f ? Present::day($f->promise_on) : null];
            }

            return $this->page($p, $items, ['aging' => $aging, 'currency' => $present->currency(), 'totals' => ['count' => count($rows), 'overdue_minor' => (int) array_sum(array_column($rows, 'overdue_minor'))]]);
        });
    }

    /** Whether a row of the list is what was typed, a phone however it is written. @param array<string, mixed> $r */
    private static function matches(array $r, string $term): bool
    {
        if (str_contains(mb_strtolower($r['tenant']['name'].' '.$r['tenant']['phone'].' '.$r['unit']['code'].' '.$r['property']['name'].' '.$r['number']), mb_strtolower($term))) {
            return true;
        }
        $digits = SearchText::looksLikePhone($term) ? SearchText::national($term) : '';

        return strlen($digits) >= 3 && (str_contains(SearchText::digits($r['tenant']['phone']), $digits) || str_contains(SearchText::digits($r['tenant']['phone_e164']), $digits));
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

    /**
     * POST rentals/follow-ups {lease_id, channel: whatsapp|sms|call|visit|note, note, promise_on, uuid}: `uuid` makes a resend from a phone's outbox the same
     * follow-up (200 with the first one); the same uuid for another lease is 409 idempotency_conflict.
     */
    public function logFollowUp(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $lease = $this->lease($request, (int) $request->input('lease_id'));
            $uuid = $request->input('uuid');
            if ($uuid !== null && $uuid !== '' && ! is_string($uuid)) {
                throw BusinessRuleException::make('uuid_invalid', 'The follow-up id is not valid.');
            }
            $uuid = $uuid ?: null;
            $replay = $uuid !== null && Str::isUuid($uuid) && FollowUps::replayed($lease, $uuid) !== null;
            $f = app(FollowUps::class)->log($this->user($request), $lease, (string) $request->input('channel', 'note'),
                $request->filled('note') ? (string) $request->input('note') : null, $this->day($request, 'promise_on'), $uuid);
            $shape = $this->present($request)->prime([$f])->followUp($f);

            return $replay ? $this->success($shape, 'Already saved.') : $this->created($shape, 'Saved.');
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

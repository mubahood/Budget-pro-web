<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Models\UnityWelfareRequest;
use App\Services\Unity\UnityAccess;
use App\Services\Unity\Welfare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Welfare & Fund: the fund book and members' requests for help. Reads unity_view, money only with unity_welfare or
 * unity_finance; writes unity_welfare. Any member may ask for help for themselves and see their own requests.
 */
class WelfareController extends UnityController
{
    /** GET unity/welfare/summary */
    public function summary(Request $request): JsonResponse
    {
        $s = Welfare::summary($this->cid($request));
        if (! $this->money($request)) {
            foreach (['balance_minor', 'in_ytd_minor', 'out_ytd_minor', 'by_month'] as $k) {
                $s[$k] = null;
            }
            $s['by_kind'] = array_map(fn ($k) => ['paid_minor' => null] + $k, $s['by_kind']);
        }

        return $this->success($s, 'Welfare.');
    }

    /** GET unity/welfare/entries?direction=&page (money readers only) */
    public function entries(Request $request): JsonResponse
    {
        if (! $this->money($request)) {
            return $this->error('The fund book is for the treasurer and the welfare officers.', 403, ['code' => 'forbidden']);
        }
        $cid = $this->cid($request);
        $q = DB::table('unity_fund_entries')->where('company_id', $cid)->where('is_deleted', 0)
            ->when(in_array($dir = $request->query('direction'), ['in', 'out'], true), fn ($w) => $w->where('direction', $dir))
            ->orderByDesc('on')->orderByDesc('id');

        return $this->pageOf($q, $request, function ($rows) use ($cid) {
            $names = Welfare::names($cid, $rows->pluck('member_id'));

            return $rows->map(fn ($e) => Welfare::shapeEntry($e, $names))->values()->all();
        });
    }

    /** POST unity/welfare/entries {direction, kind, amount_minor, member_id?, on?, method?, reference?, note?} */
    public function storeEntry(Request $request): JsonResponse
    {
        $d = $request->validate(['direction' => ['required', 'in:in,out'], 'kind' => ['required', 'string', 'max:20'], 'amount_minor' => ['required', 'integer', 'min:1'],
            'member_id' => ['sometimes', 'nullable', 'integer'], 'on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'method' => ['sometimes', 'nullable', 'string', 'max:30'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'], 'note' => ['sometimes', 'nullable', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $d) {
            $cid = $this->cid($request);
            $e = Welfare::entry($cid, $d, (int) $request->user()->id);
            $row = DB::table('unity_fund_entries')->where('id', $e->id)->first();

            return $this->created(Welfare::shapeEntry($row, Welfare::names($cid, [$row->member_id])) + ['balance_minor' => Welfare::balance($cid)], 'Recorded.');
        });
    }

    /** GET unity/welfare/requests?status=&page: officers see all, a member sees their own. */
    public function requests(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $officer = $this->can($request, 'unity_view');
            $own = $officer ? null : $this->ownMember($request);
            $money = $this->money($request) || ! $officer;
            $q = DB::table('unity_welfare_requests')->where('company_id', $cid)->where('is_deleted', 0)
                ->when($own, fn ($w) => $w->where('member_id', $own->id))
                ->when(in_array($s = $request->query('status'), Welfare::REQUEST_STATUSES, true), fn ($w) => $w->where('status', $s))
                ->orderByRaw("FIELD(status, 'pending', 'approved', 'paid', 'rejected')")->orderByDesc('id');

            return $this->pageOf($q, $request, function ($rows) use ($cid, $money) {
                $names = Welfare::names($cid, $rows->pluck('member_id'));

                return $rows->map(fn ($r) => Welfare::shapeRequest($r, $names, $money))->values()->all();
            });
        });
    }

    /** POST unity/welfare/requests {member_id?, kind, details, requested_minor}: a member asks only for themselves. */
    public function storeRequest(Request $request): JsonResponse
    {
        $d = $request->validate(['member_id' => ['sometimes', 'nullable', 'integer'], 'kind' => ['required', 'in:'.implode(',', Welfare::REQUEST_KINDS)],
            'details' => ['required', 'string', 'max:1000'], 'requested_minor' => ['required', 'integer', 'min:1']]);

        return $this->attempt(function () use ($request, $d) {
            $m = $this->memberFor($request, isset($d['member_id']) ? (int) $d['member_id'] : null, 'unity_welfare');
            $r = Welfare::request($m, $d['kind'], $d['details'], (int) $d['requested_minor'], (int) $request->user()->id);

            return $this->created($this->one($request, $r), 'Request sent.');
        });
    }

    /** POST unity/welfare/requests/{id}/approve {approved_minor, note?} */
    public function approve(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['approved_minor' => ['required', 'integer', 'min:1'], 'note' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->success($this->one($request, Welfare::approve($this->find($request, $id), $request->user(), (int) $d['approved_minor'], $d['note'] ?? null)), 'Approved.'));
    }

    /** POST unity/welfare/requests/{id}/reject {note} */
    public function reject(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->success($this->one($request, Welfare::reject($this->find($request, $id), $request->user(), $d['note'])), 'Rejected.'));
    }

    /** POST unity/welfare/requests/{id}/pay {method, on?, reference?}: 409 insufficient_fund when the fund is short. */
    public function pay(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['method' => ['required', 'string', 'max:30'], 'on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'reference' => ['sometimes', 'nullable', 'string', 'max:60']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $r = Welfare::pay($this->find($request, $id), $request->user(), $d['method'], $d['on'] ?? null, $d['reference'] ?? null);

            return $this->success($this->one($request, $r) + ['balance_minor' => Welfare::balance($this->cid($request))], 'Paid.');
        });
    }

    private function money(Request $request): bool
    {
        return $this->can($request, 'unity_welfare') || $this->can($request, 'unity_finance');
    }

    private function find(Request $request, int $id): UnityWelfareRequest
    {
        return UnityWelfareRequest::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function one(Request $request, UnityWelfareRequest $r): array
    {
        $cid = $this->cid($request);
        $row = DB::table('unity_welfare_requests')->where('id', $r->id)->first();
        $own = UnityAccess::memberId($cid, $request->user()) === (int) $row->member_id;

        return Welfare::shapeRequest($row, Welfare::names($cid, [$row->member_id]), $own || $this->money($request));
    }
}

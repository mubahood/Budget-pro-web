<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Models\SaccoPayout;
use App\Services\Sacco\Payouts\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile-money payouts (04 §11.2, §13.2 P3): what was sent to members' wallets, retried with the same key, or settled
 * by a second officer when the provider never gave a final answer.
 */
class PayoutController extends SaccoController
{
    /** GET sacco/payouts?status&purpose&member_id */
    public function index(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoPayout::withoutGlobalScopes()->where('company_id', $this->cid($request))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))->when($request->query('purpose'), fn ($q, $v) => $q->where('purpose', $v))
            ->when($request->query('member_id'), fn ($q, $v) => $q->where('member_id', (int) $v))->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($x) => PayoutService::present($x))->all());
    }

    /** GET sacco/payouts/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoPayout::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);

        return $p === null ? $this->notFound('That payout is not in this SACCO.') : $this->success(PayoutService::present($p) + ['enabled' => PayoutService::enabled()]);
    }

    /** POST sacco/payouts/{id}/retry (sacco_disburse): the same idempotency key, so never a second payment. */
    public function retry(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(PayoutService::present(app(PayoutService::class)->retry($this->company($request), $id, $this->user($request)))));
    }

    /** POST sacco/payouts/{id}/resolve {status: successful|failed, note} (sacco_approve, not the maker): after a day without an answer. */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'in:successful,failed'], 'note' => ['required', 'string', 'min:3', 'max:200']]);

        return $this->attempt(fn () => $this->success(PayoutService::present(app(PayoutService::class)->resolveByHand($this->company($request), $id, $this->user($request), $d['status'], $d['note']))));
    }
}

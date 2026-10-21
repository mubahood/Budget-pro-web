<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Services\Sacco\Payments\SaccoCollections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mobile-money collections (04 §11.1, §13.2 P3): a teller sends a member a request-to-pay for a deposit, a loan
 * repayment or shares; the money is recorded only when the provider confirms it (webhook, poll or `check`).
 */
class CollectionController extends SaccoController
{
    /** GET sacco/collections?status&member_id&purpose */
    public function index(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = DB::table('momo_requests')->where('company_id', $this->cid($request))->whereNotNull('purpose')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))->when($request->query('purpose'), fn ($q, $v) => $q->where('purpose', $v))
            ->when($request->query('member_id'), fn ($q, $v) => $q->where('sacco_member_id', (int) $v))->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($r) => SaccoCollections::present($r))->all());
    }

    /** GET sacco/collections/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $r = $this->row($request, $id);

        return $r === null ? $this->notFound('That collection is not in this SACCO.') : $this->success(SaccoCollections::present($r) + ['enabled' => SaccoCollections::enabled($this->company($request))]);
    }

    /** POST sacco/collections {uuid, purpose, savings_account_id|loan_id|member_id, amount_minor, phone?, network?} (sacco_teller) */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $before = $request->input('uuid') ? DB::table('momo_requests')->where('company_id', $this->cid($request))->where('uuid', strtolower((string) $request->input('uuid')))->exists() : false;
            $r = app(SaccoCollections::class)->request($this->company($request), $request->all(), $this->user($request));

            return $before ? $this->success(SaccoCollections::present($r), 'Already sent.')
                : $this->created(SaccoCollections::present($r), $r->status === 'failed' ? 'The provider refused the request.' : 'The prompt is on its way to the phone.');
        });
    }

    /** POST sacco/collections/{id}/check: ask the provider now (sacco_teller). */
    public function check(Request $request, int $id): JsonResponse
    {
        $r = $this->row($request, $id);
        if ($r === null) {
            return $this->notFound('That collection is not in this SACCO.');
        }

        return $this->attempt(fn () => $this->success(SaccoCollections::present(app(SaccoCollections::class)->settle((string) $r->tx_ref) ?? $r)));
    }

    /** POST sacco/collections/setup {phone, network}: the number the SACCO's collections settle to (sacco_settings). */
    public function setup(Request $request): JsonResponse
    {
        $d = $request->validate(['phone' => ['required', 'string', 'max:20'], 'network' => ['required', 'string', 'max:10']]);

        return $this->attempt(function () use ($request, $d) {
            $c = app(SaccoCollections::class)->setup($this->company($request), $this->user($request), $d['phone'], $d['network']);

            return $this->success(['ready' => SaccoCollections::enabled($c), 'payout_phone' => $c->momo_payout_phone, 'network' => $c->momo_payout_network], 'Collections settle to that number.');
        });
    }

    private function row(Request $request, int $id): ?object
    {
        return DB::table('momo_requests')->where('company_id', $this->cid($request))->whereNotNull('purpose')->where('id', $id)->first();
    }
}

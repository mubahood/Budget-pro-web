<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoDuesProgramme;
use App\Services\Kin\KinDuesMomo;
use App\Services\Sacco\Dues\DuesService;
use App\Services\Sacco\Payments\SaccoCollections;
use App\Services\Team\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contributions (dues). Everyone in the family reads; kin_dues (the head and the treasurer) writes (ApiPermissionMap). A pledge
 * and a mobile-money payment may also come from the person themself (meta.me_id): this controller judges those.
 */
class DuesController extends KinController
{
    public function __construct(private DuesService $dues)
    {
    }

    /** GET kin/dues: every programme with its totals, active first. */
    public function index(Request $request): JsonResponse
    {
        $rows = SaccoDuesProgramme::withoutGlobalScopes()->where('company_id', $this->cid($request))->orderByRaw("status = 'active' DESC")->orderByDesc('id')->get();

        return $this->success(['programmes' => $rows->map(fn ($p) => $this->dues->present($p))->values()->all()], 'Listed successfully.');
    }

    /** GET kin/dues/{id}?period=2026-11 */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $p = DuesService::find($this->cid($request), $id);
            $period = $this->period($request);
            $s = $this->dues->standing($p, $period);
            $filter = ['programme_id' => $p->id];
            if ($period !== null && ($line = \Illuminate\Support\Facades\DB::table('sacco_dues_lines')->where('programme_id', $p->id)->where('period_key', $period)->first()) !== null) {
                $filter['period'] = [$period, $line->period_start, $line->period_end];
            }

            return $this->success(['programme' => $this->dues->present($p, $s), 'period' => $period, 'periods' => DuesService::periods($p),
                'people' => $s['people'], 'payments' => $this->dues->payments($this->cid($request), $filter)]);
        });
    }

    /** POST kin/dues */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(DuesService::rules(true));

        return $this->attempt(fn () => $this->created($this->dues->present($this->dues->create($this->company($request), $d, $request->user())), 'Contribution started.'));
    }

    /** PATCH kin/dues/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(DuesService::rules(false));

        return $this->attempt(fn () => $this->success($this->dues->present($this->dues->update(DuesService::find($this->cid($request), $id), $d)), 'Saved.'));
    }

    /** POST kin/dues/{id}/close */
    public function close(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->dues->present($this->dues->close(DuesService::find($this->cid($request), $id))), 'Contribution closed.'));
    }

    /** POST kin/dues/{id}/payments */
    public function pay(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(DuesService::paymentRules());

        return $this->attempt(function () use ($request, $id, $d) {
            $p = DuesService::find($this->cid($request), $id);
            $pay = $this->dues->pay($this->company($request), $p, $d, $request->user());

            return $this->created($this->dues->presentPayments($this->cid($request), collect([$pay]))[0], 'Payment recorded.');
        });
    }

    /** POST kin/dues/payments/{id}/reverse {reason} */
    public function reverse(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $back = $this->dues->reverse($this->company($request), $id, $d['reason'], $request->user());

            return $this->created($this->dues->presentPayments($this->cid($request), collect([$back]))[0], 'Payment reversed.');
        });
    }

    /** GET kin/dues/mine */
    public function mine(Request $request): JsonResponse
    {
        return $this->success($this->dues->mine($request->user(), $this->cid($request)));
    }

    /** GET kin/dues/{id}/share?period= */
    public function share(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(['text' => $this->dues->shareText($this->company($request), DuesService::find($this->cid($request), $id), $this->period($request))]));
    }

    /** POST kin/dues/{id}/pledge {member_id, amount_minor}: a one-time contribution's amount for one person (yourself; kin_dues for anyone). */
    public function pledge(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['member_id' => ['required', 'integer'], 'amount_minor' => ['required', 'integer', 'min:0', 'max:'.DuesService::MAX_MINOR]]);

        return $this->attempt(function () use ($request, $id, $d) {
            $cid = $this->cid($request);
            $p = DuesService::find($cid, $id);
            $me = DuesService::personOf($request->user(), $cid);
            if (($me === null || (int) $me->id !== (int) $d['member_id']) && ! Permissions::can($request->user(), 'kin_dues')) {
                throw BusinessRuleException::make('forbidden', 'You may pledge for yourself only.');
            }
            $amount = $this->dues->pledge($p, (int) $d['member_id'], (int) $d['amount_minor']);

            return $this->success(['programme_id' => (int) $p->id, 'member_id' => (int) $d['member_id'], 'pledged_minor' => $amount,
                'balance_minor' => max(0, DuesService::balanceOf($p, (int) $d['member_id'])), 'programme' => $this->dues->present($p->fresh())], 'Pledge saved.');
        });
    }

    /** GET kin/dues/momo: may this family's people pay by mobile money? */
    public function momoStatus(Request $request): JsonResponse
    {
        return $this->success(KinDuesMomo::status($this->company($request)));
    }

    /** POST kin/dues/momo/setup {phone, network}: the number the family's collections settle to (the head). */
    public function momoSetup(Request $request): JsonResponse
    {
        $d = $request->validate(['phone' => ['required', 'string', 'max:20'], 'network' => ['required', 'string', 'max:10']]);

        return $this->attempt(function () use ($request, $d) {
            $c = app(KinDuesMomo::class)->setup($this->company($request), $request->user(), $d['phone'], $d['network']);

            return $this->success(KinDuesMomo::status($c) + ['payout_phone' => $c->momo_payout_phone, 'network' => $c->momo_payout_network], 'Payments settle to that number.');
        });
    }

    /** POST kin/dues/{id}/momo {member_id, amount_minor, phone?, network?, period?, uuid?}: the prompt goes to the phone; nothing is recorded yet. */
    public function momo(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['member_id' => ['required', 'integer'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:'.DuesService::MAX_MINOR],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'], 'network' => ['sometimes', 'nullable', 'string', 'max:10'],
            'period' => ['sometimes', 'nullable', 'string', 'max:10'], 'uuid' => ['sometimes', 'nullable', 'uuid']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $r = app(KinDuesMomo::class)->request($this->company($request), $id, $d, $request->user());
            $out = KinDuesMomo::present($r);
            if ($out['status'] === 'failed') {
                return $this->error('The mobile-money prompt could not be sent. Try again, or give the money to the treasurer.', 422, ['code' => 'momo_refused', 'request_id' => $out['request_id']]);
            }

            return $this->created($out + ['message' => 'Check your phone and enter your PIN to pay.'], 'The prompt is on its way to the phone.');
        });
    }

    /** GET kin/dues/momo/{request_id}: asks the provider, then answers pending | paid | failed | expired (the requester, or kin_dues). */
    public function momoShow(Request $request, int $requestId): JsonResponse
    {
        return $this->attempt(function () use ($request, $requestId) {
            $r = DB::table('momo_requests')->where('company_id', $this->cid($request))->where('purpose', KinDuesMomo::PURPOSE)->where('id', $requestId)->first();
            if ($r === null || ((int) $r->created_by_id !== (int) $request->user()->id && ! Permissions::can($request->user(), 'kin_dues'))) {
                throw BusinessRuleException::make('not_found', 'That payment was not found.');
            }
            if ($r->status === 'pending') {
                try {
                    $r = app(SaccoCollections::class)->settle((string) $r->tx_ref) ?? $r;
                } catch (BusinessRuleException) {
                    // collections switched off since: it stays pending
                }
            }

            return $this->success(KinDuesMomo::present($r));
        });
    }

    private function period(Request $request): ?string
    {
        $p = trim((string) $request->query('period', ''));

        return $p === '' ? null : $p;
    }
}

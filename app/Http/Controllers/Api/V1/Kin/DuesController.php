<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Models\SaccoDuesProgramme;
use App\Services\Sacco\Dues\DuesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contributions (dues). Everyone in the family reads; kin_dues (the head and the treasurer) writes (ApiPermissionMap). */
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

    private function period(Request $request): ?string
    {
        $p = trim((string) $request->query('period', ''));

        return $p === '' ? null : $p;
    }
}

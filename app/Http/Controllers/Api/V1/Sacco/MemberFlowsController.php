<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SaccoLoan;
use App\Models\SaccoMember;
use App\Models\SaccoMemberNotice;
use App\Services\Sacco\Simple\LoanFlow;
use App\Services\Sacco\Simple\ShareOut;
use App\Services\Sacco\Simple\ShareRegister;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The simple flows for a signed-in member (a member token): their shares, their part of a share-out now, their notices,
 * asking for a loan (with the cost and schedule first, and one guarantor), and the requests they are asked to guarantee.
 */
class MemberFlowsController extends Controller
{
    use ApiResponse;

    /** GET sacco/me/shares */
    public function shares(Request $request): JsonResponse
    {
        $m = $this->member($request);
        $r = ShareRegister::for((int) $m->company_id, (int) $m->id);

        return $this->success(['share_price_minor' => $r['share_price_minor'], 'currency' => $r['currency'], 'shares' => $r['rows'][0]['shares'] ?? 0,
            'value_minor' => $r['rows'][0]['value_minor'] ?? 0, 'sacco_total_shares' => $r['total_shares'], 'sacco_total_value_minor' => $r['total_value_minor']]);
    }

    /** GET sacco/me/share-out: what this member would get if the SACCO shared out now. */
    public function shareout(Request $request): JsonResponse
    {
        $m = $this->member($request);
        $p = (new ShareOut())->preview((int) $m->company_id);
        $mine = collect($p['members'])->firstWhere('member_id', (int) $m->id);

        return $this->success(['formula' => $p['formula'], 'currency' => $p['currency'], 'profit_minor' => $p['profit_minor'], 'total_shares' => $p['total_shares'],
            'savings_minor' => $mine['savings_minor'] ?? 0, 'shares' => $mine['shares'] ?? 0, 'profit_share_minor' => $mine['profit_minor'] ?? 0, 'payout_minor' => $mine['payout_minor'] ?? 0]);
    }

    /** GET sacco/me/notices */
    public function notices(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return $this->success(SaccoMemberNotice::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)->orderByDesc('id')->limit(100)->get()
            ->map(fn ($n) => ['id' => (int) $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body, 'loan_id' => $n->loan_id, 'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String()])->all(), 'Listed successfully.', 200,
            ['unread' => SaccoMemberNotice::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)->whereNull('read_at')->count()]);
    }

    /** POST sacco/me/notices/read: mark them all read. */
    public function readNotices(Request $request): JsonResponse
    {
        $m = $this->member($request);
        SaccoMemberNotice::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->success(null, 'Marked read.');
    }

    /** POST sacco/me/loan-quote {amount_minor, months}: the instalment, the interest, the total and the schedule. */
    public function quote(Request $request): JsonResponse
    {
        $d = $request->validate(['amount_minor' => \App\Support\Rules\SaccoRules::minor(true, 1), 'months' => ['required', 'integer', 'min:1', 'max:60']]);

        return $this->attempt(fn () => $this->success((new LoanFlow())->quote((int) $this->member($request)->company_id, (int) $d['amount_minor'], (int) $d['months'])));
    }

    /** GET sacco/me/guarantor-options: the members this member may pick. */
    public function guarantorOptions(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return $this->success((new LoanFlow())->guarantorOptions((int) $m->company_id, (int) $m->id));
    }

    /** GET sacco/me/loan-requests: this member's own requests and loans. */
    public function requests(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return $this->success((new LoanFlow())->list((int) $m->company_id, null, (int) $m->id));
    }

    /** POST sacco/me/loan-requests {uuid?, amount_minor, months, purpose?, guarantor_member_id} */
    public function ask(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $flow = new LoanFlow();
            $loan = $flow->request($this->company($request), $this->member($request), $request->only(['uuid', 'amount_minor', 'months', 'purpose', 'guarantor_member_id']));

            return $this->created($flow->present($loan), 'Your request was sent to your guarantor.');
        });
    }

    /** GET sacco/me/loan-requests/{id}: one of theirs, or one they were asked to guarantee. */
    public function request(Request $request, int $id): JsonResponse
    {
        $m = $this->member($request);
        $loan = SaccoLoan::withoutGlobalScopes()->where('company_id', $m->company_id)->find($id);
        if ($loan === null || ! LoanFlow::memberMaySee($m, $loan)) {
            return $this->notFound('Not found.');
        }

        return $this->success((new LoanFlow())->present($loan));
    }

    /** GET sacco/me/guarantor-requests: requests this member is asked to guarantee (and past ones). */
    public function guarantorRequests(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return $this->success((new LoanFlow())->guaranteeRequests((int) $m->company_id, (int) $m->id));
    }

    /** POST sacco/me/guarantor-requests/{loanId} {decision approve|decline, note?} */
    public function decide(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', 'in:approve,decline'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $flow = new LoanFlow();

            return $this->success($flow->present($flow->guarantorDecide($this->company($request), $this->member($request), $id, $d['decision'], $d['note'] ?? null)));
        });
    }

    private function member(Request $request): SaccoMember
    {
        return $request->attributes->get('sacco_member');
    }

    private function company(Request $request): Company
    {
        return $request->attributes->get('company') ?? Company::withoutGlobalScopes()->findOrFail((int) $this->member($request)->company_id);
    }

    /** @param callable(): JsonResponse $fn */
    private function attempt(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), $e->errorCode() === 'forbidden' ? 403 : (in_array($e->errorCode(), SaccoController::NOT_FOUND, true) ? 404 : 422), $e->toErrors());
        }
    }
}

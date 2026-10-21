<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Services\Sacco\Payments\SaccoCollections;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A member's own money requests from the app (04 §13.2 P3, `sacco/me/*`, a member token only): paying in by mobile
 * money. A request never moves money by itself: the receipt is recorded when the provider confirms the payment.
 */
class MemberMoneyController extends Controller
{
    use ApiResponse;

    /** Refusals that mean "not yours" (the same answer as for another member's or SACCO's ids). */
    private const NOT_FOUND = ['account_not_found', 'loan_not_found', 'member_not_found', 'not_found', 'transaction_not_found'];

    /** POST sacco/me/collections {uuid, purpose: sacco_deposit|sacco_repayment|sacco_shares, savings_account_id|loan_id, amount_minor, phone?, network?} */
    public function collect(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return $this->attempt(function () use ($request, $m) {
            $data = $request->only(['uuid', 'purpose', 'savings_account_id', 'loan_id', 'amount_minor', 'phone', 'network']);
            $before = ! empty($data['uuid']) && DB::table('momo_requests')->where('company_id', $m->company_id)->where('uuid', strtolower((string) $data['uuid']))->exists();
            $r = app(SaccoCollections::class)->request($this->company($request), $data, null, $m);

            return $before ? $this->success(SaccoCollections::present($r), 'Already sent.')
                : $this->created(SaccoCollections::present($r), $r->status === 'failed' ? 'The payment could not be started.' : 'Approve the payment on your phone.');
        });
    }

    /** GET sacco/me/collections: the member's own requests, newest first. */
    public function collections(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return $this->success(DB::table('momo_requests')->where('company_id', $m->company_id)->where('sacco_member_id', $m->id)->whereNotNull('purpose')->orderByDesc('id')->limit(50)
            ->get()->map(fn ($r) => SaccoCollections::present($r))->all());
    }

    /** GET sacco/me/collections/{id}: one of the member's requests; a pending one is checked with the provider first. */
    public function collection(Request $request, int $id): JsonResponse
    {
        $m = $this->member($request);
        $r = DB::table('momo_requests')->where('company_id', $m->company_id)->where('sacco_member_id', $m->id)->whereNotNull('purpose')->where('id', $id)->first();
        if ($r === null) {
            return $this->notFound('Not found.');
        }
        if ($r->status === 'pending') {
            try {
                $r = app(SaccoCollections::class)->settle((string) $r->tx_ref) ?? $r;
            } catch (BusinessRuleException) {
                // collections switched off since: the request stays pending and the poll answers later
            }
        }

        return $this->success(SaccoCollections::present($r));
    }

    private function member(Request $request): SaccoMember
    {
        return $request->attributes->get('sacco_member');
    }

    private function company(Request $request): Company
    {
        return $request->attributes->get('company');
    }

    /** @param callable(): JsonResponse $fn */
    private function attempt(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), $e->errorCode() === 'forbidden' ? 403 : (in_array($e->errorCode(), self::NOT_FOUND, true) ? 404 : 422), $e->toErrors());
        }
    }
}

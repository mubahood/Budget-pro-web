<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoAccountEntry;
use App\Models\SaccoLoan;
use App\Models\SaccoShareout;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Simple\LoanFlow;
use App\Services\Sacco\Simple\Money;
use App\Services\Sacco\Simple\SaccoAccount;
use App\Services\Sacco\Simple\ShareOut;
use App\Services\Sacco\Simple\ShareRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The simple flows for officers (docs/akiba/flows-plan.md): the share register, the SACCO's own account, the
 * share-out, and loan requests from the chairperson's approval to the last repayment. Thin: every write is a service.
 */
class FlowsController extends SaccoController
{
    // ---------------------------------------------------------------- 1. shares

    /** GET sacco/shares: every member's shares and value, the SACCO's total and the share price. */
    public function shares(Request $request): JsonResponse
    {
        return $this->mayRead($request) ? $this->attempt(fn () => $this->success(ShareRegister::for($this->cid($request)))) : $this->refuseRead();
    }

    // ---------------------------------------------------------------- 2. the SACCO account

    /** GET sacco/account: the SACCO balance (cash, bank, mobile money, total) and the categories. */
    public function account(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $cid = $this->cid($request);

        return $this->attempt(fn () => $this->success(['balance' => Money::balances($cid), 'currency' => LoanBooks::settings($cid)->currency,
            'methods' => Money::METHODS, 'categories' => array_map(fn ($c) => SaccoAccount::presentCategory($c), (new SaccoAccount())->categories($cid))]));
    }

    /** GET sacco/account/statement?from&to: every movement of the SACCO's money, with the running balance. */
    public function statement(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return $this->attempt(fn () => $this->success((new SaccoAccount())->statement($this->cid($request), $d['from'] ?? null, $d['to'] ?? null)));
    }

    /** GET sacco/account/entries?direction */
    public function entries(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoAccountEntry::withoutGlobalScopes()->where('company_id', $this->cid($request))
            ->when(in_array($request->query('direction'), ['in', 'out'], true), fn ($q) => $q->where('direction', $request->query('direction')))
            ->orderByDesc('value_date')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($e) => SaccoAccount::present($e))->all());
    }

    /** POST sacco/account/entries {uuid?, direction, category_id, amount_minor, value_date?, method, notes?} */
    public function record(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $r = (new SaccoAccount())->record($this->company($request), $this->user($request), $request->only(['uuid', 'direction', 'category_id', 'amount_minor', 'value_date', 'method', 'notes']));

            return $r['replayed'] ? $this->success(SaccoAccount::present($r['entry']), 'Already recorded.') : $this->created(SaccoAccount::present($r['entry']), 'Recorded.');
        });
    }

    /** POST sacco/account/entries/{id}/reverse {reason} */
    public function reverse(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->success(SaccoAccount::present((new SaccoAccount())->reverse($this->company($request), $this->user($request), $id, $d['reason'])), 'Reversed.'));
    }

    /** GET sacco/account/categories */
    public function categories(Request $request): JsonResponse
    {
        return $this->mayRead($request) ? $this->success(array_map(fn ($c) => SaccoAccount::presentCategory($c), (new SaccoAccount())->categories($this->cid($request)))) : $this->refuseRead();
    }

    /** POST sacco/account/categories {direction, name} */
    public function storeCategory(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->created(SaccoAccount::presentCategory((new SaccoAccount())->saveCategory($this->company($request), $this->user($request), $request->only(['direction', 'name'])))));
    }

    /** PATCH sacco/account/categories/{id} {name?, is_active?} */
    public function updateCategory(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(SaccoAccount::presentCategory((new SaccoAccount())->saveCategory($this->company($request), $this->user($request), $request->only(['name', 'is_active']), $id))));
    }

    // ---------------------------------------------------------------- 3. share-out

    /** GET sacco/share-out/preview?amount_minor: what everyone gets if the SACCO shares out now. */
    public function preview(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $d = $request->validate(['amount_minor' => ['nullable', 'integer', 'min:0']]);

        return $this->attempt(fn () => $this->success((new ShareOut())->preview($this->cid($request), isset($d['amount_minor']) ? (int) $d['amount_minor'] : null)));
    }

    /** GET sacco/share-outs */
    public function shareouts(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->success(SaccoShareout::withoutGlobalScopes()->where('company_id', $this->cid($request))->orderByDesc('share_on')->get()->map(fn ($s) => ShareOut::present($s))->all());
    }

    /** POST sacco/share-outs {uuid?, share_on?, amount_minor, method?, notes?}: run it (once; locked after). */
    public function runShareout(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $before = $request->input('uuid') !== null && SaccoShareout::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('uuid', strtolower((string) $request->input('uuid')))->exists();
            $s = (new ShareOut())->run($this->company($request), $this->user($request), $request->only(['uuid', 'share_on', 'amount_minor', 'method', 'notes']));

            return $before ? $this->success(ShareOut::present($s, true), 'Already run.') : $this->created(ShareOut::present($s, true), 'Shared out.');
        });
    }

    /** GET sacco/share-outs/{id}: with one line per member. */
    public function shareout(Request $request, int $id): JsonResponse
    {
        return $this->mayRead($request) ? $this->attempt(fn () => $this->success((new ShareOut())->show($this->cid($request), $id))) : $this->refuseRead();
    }

    /** GET sacco/share-outs/{id}/slips/{memberId}: the member's payout slip (PDF). */
    public function slip(Request $request, int $id, int $memberId): Response|JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        try {
            $pdf = (new ShareOut())->slipPdf($this->cid($request), $id, $memberId);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 404, $e->toErrors());
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="shareout-'.$id.'-'.$memberId.'.pdf"']);
    }

    // ---------------------------------------------------------------- 4 and 5. loans

    /** GET sacco/loan-settings: the interest rate, its period and the method (reducing or flat). */
    public function loanSettings(Request $request): JsonResponse
    {
        return $this->mayRead($request) ? $this->attempt(fn () => $this->success((new LoanFlow())->settings($this->cid($request)))) : $this->refuseRead();
    }

    /** PUT sacco/loan-settings {rate_bp?, rate_period? month|year, method? reducing|flat} */
    public function updateLoanSettings(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->success((new LoanFlow())->updateSettings($this->company($request), $this->user($request), $request->only(['rate_bp', 'rate_period', 'method'])), 'Saved.'));
    }

    /** GET sacco/loan-requests?status=pending_chair,approved,... */
    public function loanRequests(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $status = $request->query('status');
        $statuses = is_string($status) && $status !== '' ? array_values(array_filter(explode(',', $status))) : null;

        return $this->success((new LoanFlow())->list($this->cid($request), $statuses, $request->query('member_id') !== null ? (int) $request->query('member_id') : null));
    }

    /** GET sacco/loan-requests/{id}: the request, its steps, the schedule and the position. */
    public function loanRequest(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(function () use ($request, $id) {
            $flow = new LoanFlow();
            $loan = LoanBooks::find($this->cid($request), $id);

            return $this->success($flow->present($loan) + ['can' => $this->can($request, $loan)]);
        });
    }

    /** POST sacco/loan-requests/{id}/chair {decision approve|decline, note?} */
    public function chair(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', 'in:approve,decline'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->success((new LoanFlow())->present((new LoanFlow())->chairDecide($this->company($request), $this->user($request), $id, $d['decision'], $d['note'] ?? null))));
    }

    /** POST sacco/loan-requests/{id}/pay-out {paid_on?, method cash|bank|mobile_money, notes?} (once) */
    public function payOut(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success((new LoanFlow())->present((new LoanFlow())->payOut($this->company($request), $this->user($request), $id, $request->only(['paid_on', 'method', 'notes']))), 'Paid out.'));
    }

    /** POST sacco/loan-requests/{id}/not-paid-out {notes} */
    public function notPaidOut(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['notes' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->success((new LoanFlow())->present((new LoanFlow())->notPaidOut($this->company($request), $this->user($request), $id, $d['notes']))));
    }

    /** POST sacco/loan-requests/{id}/repayments {uuid?, amount_minor, paid_on?, method? cash|bank|savings, savings_account_id?} */
    public function repay(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $flow = new LoanFlow();
            $r = $flow->repay($this->company($request), $this->user($request), $id, $request->only(['uuid', 'amount_minor', 'paid_on', 'method', 'savings_account_id']));

            return $this->created(['repayment' => ['id' => (int) $r->id, 'amount_minor' => (int) $r->amount_minor, 'penalty_minor' => (int) $r->penalty_minor,
                'interest_minor' => (int) $r->interest_minor + (int) $r->interest_suspense_minor + (int) $r->interest_to_day_minor, 'principal_minor' => (int) $r->principal_minor,
                'overpayment_minor' => (int) $r->overpayment_minor, 'transaction_id' => $r->transaction_id], 'loan' => $flow->present(LoanBooks::find($this->cid($request), $id))], 'Repayment recorded.');
        });
    }

    /** @return array<string, bool> what this officer may do to the loan now */
    private function can(Request $request, SaccoLoan $loan): array
    {
        $user = $this->user($request);
        $status = LoanFlow::status($loan);

        return ['chair' => $status === 'pending_chair' && (new LoanFlow())->isChair($this->company($request), $user),
            'pay_out' => $status === 'approved' && \App\Services\Sacco\SaccoAccess::can($user, 'sacco_disburse'),
            'repay' => $status === 'disbursed' && \App\Services\Sacco\SaccoAccess::can($user, 'sacco_teller')];
    }
}

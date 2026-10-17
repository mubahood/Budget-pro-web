<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\ContributionPayment;
use App\Models\ContributionRecord;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\ContributionPayments;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pledge payments (roadmap 02 §6): the history of a pledge, a new payment, and a reversal. Append-only;
 * paid_amount is the sum (ContributionPayments). Treasurers and chairs of the event record payments, and
 * so do the owner and anyone with manage_budget (BudgetAccess). Retry-safe twice over: the `uuid` in the
 * body and the Idempotency-Key header (api.idempotent).
 */
class ContributionPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ContributionPayments $payments = new ContributionPayments())
    {
    }

    /** GET contribution-records/{id}/payments — the pledge's payments, newest first, with its figures. */
    public function index(Request $request, $id): JsonResponse
    {
        $record = $this->pledge($request, $id);
        if ($record === null) {
            return $this->notFound('Pledge not found.');
        }
        $rows = ContributionPayment::withoutGlobalScopes()->with('receivedBy:id,name')->where('company_id', $record->company_id)
            ->where('contribution_record_id', $record->id)->where('is_deleted', 0)->orderByDesc('id')->get();
        $reversed = $rows->whereNotNull('reverses_id')->pluck('reverses_id')->map(fn ($v) => (int) $v)->all();

        return $this->success([
            'pledge' => $this->figures($record),
            'payments' => $rows->map(fn (ContributionPayment $p) => $this->present($p, in_array((int) $p->id, $reversed, true)))->values()->all(),
            'abilities' => BudgetAccess::abilities($request->user(), (int) $record->budget_program_id),
        ], 'Payments listed.');
    }

    /** POST contribution-records/{id}/payments {amount, paid_on?, method?, receipt_no?, reference?, notes?, uuid?} */
    public function store(Request $request, $id): JsonResponse
    {
        $record = $this->pledge($request, $id);
        if ($record === null) {
            return $this->notFound('Pledge not found.');
        }
        if (! BudgetAccess::can($request->user(), (int) $record->budget_program_id, BudgetAccess::PAYMENT)) {
            return $this->refused(BudgetAccess::PAYMENT, 'Only the treasurer or the chair of this event can record payments.');
        }
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_on' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:30'],
            'receipt_no' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
            'uuid' => ['nullable', 'uuid'],
        ]);
        $data['amount'] = (string) $request->input('amount'); // as typed, never through a float

        try {
            $r = $this->payments->record((int) $record->company_id, (int) $record->id, $data, (int) $request->user()->id);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        $body = ['payment' => $this->present($r['payment']), 'pledge' => $this->figures($r['record']->fresh() ?? $r['record']), 'replayed' => $r['replayed']];

        return $r['replayed'] ? $this->success($body, 'Payment already recorded.') : $this->created($body, 'Payment recorded.');
    }

    /** POST contribution-payments/{id}/reverse {reason, uuid?} — a new negative row; the payment itself stays. */
    public function reverse(Request $request, $id): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $payment = ContributionPayment::withoutGlobalScopes()->where('company_id', $companyId)->where('id', (int) $id)->where('is_deleted', 0)->first();
        if ($payment === null) {
            return $this->notFound('Payment not found.');
        }
        if (! BudgetAccess::can($request->user(), (int) $payment->budget_program_id, BudgetAccess::PAYMENT)) {
            return $this->refused(BudgetAccess::PAYMENT, 'Only the treasurer or the chair of this event can reverse payments.');
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255'], 'uuid' => ['nullable', 'uuid']]);

        try {
            $r = $this->payments->reverse($companyId, (int) $payment->id, $data['reason'], (int) $request->user()->id, $data['uuid'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }
        $body = ['payment' => $this->present($r['payment']), 'pledge' => $this->figures($r['record']->fresh() ?? $r['record']), 'replayed' => $r['replayed']];

        return $r['replayed'] ? $this->success($body, 'Payment already reversed.') : $this->created($body, 'Payment reversed.');
    }

    private function pledge(Request $request, $id): ?ContributionRecord
    {
        return ContributionRecord::withoutGlobalScopes()->without(['budgetProgram', 'treasurer', 'changedBy'])
            ->where('company_id', (int) $request->user()->company_id)->where('id', (int) $id)->where('is_deleted', 0)->first();
    }

    private function figures(ContributionRecord $r): array
    {
        return ['id' => (int) $r->id, 'uuid' => $r->uuid, 'name' => $r->name, 'phone' => $r->phone, 'budget_program_id' => (int) $r->budget_program_id,
            'amount' => (string) $r->amount, 'paid_amount' => (string) $r->paid_amount, 'not_paid_amount' => (string) $r->not_paid_amount,
            'fully_paid' => $r->fully_paid, 'version' => (int) $r->version, 'server_seq' => (int) $r->server_seq];
    }

    private function present(ContributionPayment $p, bool $reversed = false): array
    {
        return [
            'id' => (int) $p->id, 'uuid' => $p->uuid, 'contribution_record_id' => (int) $p->contribution_record_id, 'budget_program_id' => (int) $p->budget_program_id,
            'amount' => (string) $p->amount, 'paid_on' => substr((string) ($p->getAttributes()['paid_on'] ?? ''), 0, 10), 'method' => $p->method, 'receipt_no' => $p->receipt_no,
            'reference' => $p->reference, 'notes' => $p->notes, 'source' => $p->source, 'reverses_id' => $p->reverses_id ? (int) $p->reverses_id : null,
            'reason' => $p->reason, 'is_reversed' => $reversed, 'received_by_id' => $p->received_by_id ? (int) $p->received_by_id : null,
            'received_by_text' => $p->relationLoaded('receivedBy') ? $p->receivedBy?->name : \App\Models\User::withoutGlobalScopes()->whereKey($p->received_by_id)->value('name'),
            'created_at' => $p->created_at?->toIso8601String(), 'server_seq' => (int) $p->server_seq,
        ];
    }

    private function refused(string $ability, string $message): JsonResponse
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => null, 'errors' => ['code' => 'forbidden', 'permission' => $ability]], 403);
    }
}

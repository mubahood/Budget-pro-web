<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\BudgetItem;
use App\Models\BudgetPayment;
use App\Models\BudgetProgram;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\BudgetPayments;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Spending (the review's PB-13): a line's history, a new payment out, one with no line (Not on the budget) and a reversal. Append-only; a line's
 * invested_amount is the sum (BudgetPayments). The chair, the treasurer and full access may write (BudgetAccess::SPENDING); a seat reads. Retry-safe:
 * the `uuid` in the body.
 */
class BudgetPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BudgetPayments $payments = new BudgetPayments())
    {
    }

    /** GET budget-items/{id}/payments: the line's history, oldest first, with its figures. */
    public function index(Request $request, $id): JsonResponse
    {
        $item = $this->line($request, $id);
        if ($item === null || ! BudgetAccess::canOpen($request->user(), (int) $item->budget_program_id)) {
            return $this->notFound('Budget line not found.');
        }

        return $this->success([
            'item' => $this->figures($item),
            'payments' => $this->payments->history((int) $item->company_id, (int) $item->id)->map(fn ($r) => $this->row($r))->values()->all(),
            'abilities' => BudgetAccess::abilities($request->user(), (int) $item->budget_program_id),
        ], 'Payments out listed.');
    }

    /** GET budget-programs/{id}/budget-payments?search=&method=&vendor=: every row on the event's live lines, newest first; they add up to Paid out. */
    public function ledger(Request $request, $id): JsonResponse
    {
        $event = BudgetProgram::withoutGlobalScopes()->where('company_id', (int) $request->user()->company_id)->where('id', (int) $id)->where('is_deleted', 0)->first();
        if ($event === null || ! BudgetAccess::canOpen($request->user(), (int) $event->id)) {
            return $this->notFound('Event not found.');
        }
        $rows = $this->payments->ledger((int) $event->company_id, (int) $event->id, array_filter(['search' => (string) $request->query('search', ''), 'method' => (string) $request->query('method', ''), 'vendor' => (string) $request->query('vendor', '')]))
            ->orderByDesc('p.paid_on')->orderByDesc('p.id')->limit(1000)->get();

        return $this->success(['payments' => $rows->map(fn ($r) => $this->row($r) + ['line' => $r->line, 'category' => $r->category])->values()->all()], 'Payments out listed.');
    }

    /** POST budget-items/{id}/payments {amount, paid_on?, method?, paid_to?, reference?, notes?, uuid?, paid_by_id?, approved_by_id?, check_duplicates?, confirm_duplicate?} */
    public function store(Request $request, $id): JsonResponse
    {
        $item = $this->line($request, $id);
        if ($item === null) {
            return $this->notFound('Budget line not found.');
        }

        return $this->write($request, (int) $item->budget_program_id, (int) $item->id);
    }

    /** POST budget-programs/{id}/budget-payments: as above, for money with no line: it goes on the event's Not on the budget line. */
    public function storeOffBudget(Request $request, $id): JsonResponse
    {
        $event = BudgetProgram::withoutGlobalScopes()->where('company_id', (int) $request->user()->company_id)->where('id', (int) $id)->where('is_deleted', 0)->first();
        if ($event === null) {
            return $this->notFound('Event not found.');
        }

        return $this->write($request, (int) $event->id, null);
    }

    /** POST budget-payments/{id}/reverse {reason, uuid?}: a new negative row; the payment itself stays. */
    public function reverse(Request $request, $id): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $payment = BudgetPayment::withoutGlobalScopes()->where('company_id', $companyId)->where('id', (int) $id)->where('is_deleted', 0)->first();
        if ($payment === null) {
            return $this->notFound('Payment not found.');
        }
        if (! BudgetAccess::can($request->user(), (int) $payment->budget_program_id, BudgetAccess::SPENDING)) {
            return $this->refused('Only the treasurer or the chair of this event can reverse payments out.');
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255'], 'uuid' => ['nullable', 'uuid']]);

        try {
            $r = $this->payments->reverse($companyId, (int) $payment->id, $data['reason'], (int) $request->user()->id, $data['uuid'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->answer($r, 'Payment out already reversed.', 'Payment out reversed.');
    }

    private function write(Request $request, int $programId, ?int $itemId): JsonResponse
    {
        if (! BudgetAccess::can($request->user(), $programId, BudgetAccess::SPENDING)) {
            return $this->refused('Only the treasurer or the chair of this event can record payments out.');
        }
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'], 'paid_on' => ['nullable', 'date'], 'method' => ['nullable', 'string', 'max:30'], 'paid_to' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:255'], 'uuid' => ['nullable', 'uuid'], 'paid_by_id' => ['nullable', 'integer'],
            'approved_by_id' => ['nullable', 'integer'], 'check_duplicates' => ['nullable', 'boolean'], 'confirm_duplicate' => ['nullable', 'boolean'],
        ]);
        $data['amount'] = (string) $request->input('amount'); // as typed, never through a float
        $data['confirm_duplicate'] = ! $request->boolean('check_duplicates') || $request->boolean('confirm_duplicate');
        $data['budget_program_id'] = $programId;

        try {
            $r = $this->payments->record((int) $request->user()->company_id, $itemId, $data, (int) $request->user()->id);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->answer($r, 'Payment out already recorded.', 'Payment out recorded.');
    }

    /** @param array{payment: BudgetPayment, item: BudgetItem, replayed: bool} $r */
    private function answer(array $r, string $again, string $done): JsonResponse
    {
        $p = $r['payment'];
        $body = ['payment' => ['id' => (int) $p->id, 'uuid' => $p->uuid, 'budget_item_id' => (int) $p->budget_item_id, 'budget_program_id' => (int) $p->budget_program_id, 'amount' => (string) $p->amount,
            'paid_on' => substr((string) ($p->getAttributes()['paid_on'] ?? ''), 0, 10), 'method' => $p->method, 'paid_to' => $p->paid_to, 'reference' => $p->reference, 'notes' => $p->notes, 'source' => $p->source,
            'reverses_id' => $p->reverses_id ? (int) $p->reverses_id : null, 'reason' => $p->reason, 'paid_by_id' => $p->paid_by_id ? (int) $p->paid_by_id : null, 'approved_by_id' => $p->approved_by_id ? (int) $p->approved_by_id : null,
            'created_by_id' => $p->created_by_id ? (int) $p->created_by_id : null, 'server_seq' => (int) $p->server_seq], 'item' => $this->figures($r['item']->fresh() ?? $r['item']), 'replayed' => $r['replayed']];

        return $r['replayed'] ? $this->success($body, $again) : $this->created($body, $done);
    }

    private function line(Request $request, $id): ?BudgetItem
    {
        return BudgetItem::withoutGlobalScopes()->without(['budgetItemCategory', 'createdBy'])->where('company_id', (int) $request->user()->company_id)->where('id', (int) $id)->where('is_deleted', 0)->first();
    }

    private function figures(BudgetItem $i): array
    {
        return ['id' => (int) $i->id, 'uuid' => $i->uuid, 'name' => $i->name, 'budget_program_id' => (int) $i->budget_program_id, 'target_amount' => (string) $i->target_amount, 'invested_amount' => (string) $i->invested_amount,
            'balance' => (string) $i->balance, 'version' => (int) $i->version, 'server_seq' => (int) $i->server_seq];
    }

    private function row(object $r): array
    {
        return ['id' => (int) $r->id, 'uuid' => $r->uuid, 'budget_item_id' => (int) $r->budget_item_id, 'amount' => (string) $r->amount, 'paid_on' => substr((string) $r->paid_on, 0, 10), 'paid_to' => $r->paid_to, 'method' => $r->method,
            'paid_by_text' => $r->paid_by, 'approved_by_text' => $r->approved_by, 'created_by_text' => $r->recorded_by, 'reference' => $r->reference, 'notes' => $r->notes, 'source' => $r->source,
            'reverses_id' => $r->reverses_id ? (int) $r->reverses_id : null, 'reason' => $r->reason, 'is_reversed' => (bool) ($r->reversed ?? false)];
    }

    private function refused(string $message): JsonResponse
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => null, 'errors' => ['code' => 'forbidden', 'permission' => BudgetAccess::SPENDING]], 403);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Shop\StockRequestService;
use App\Services\Shop\TransferService;
use App\Support\StoreScope;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse to stores (SUPERMARKET_PLAN.md G2) for the phone, the same flow as the web Transfers screen
 * (budget-pro-new StockRequests): a store asks for stock, the warehouse approves and sends it (a transfer
 * "in transit"), the store receives what arrived. Every step is StockRequestService / TransferService, with the
 * member's own store (StoreScope, G3) as the scope. Writes need `adjust` (ApiPermissionMap).
 *
 *  GET stock-requests?status= · GET stock-requests/{id} · POST stock-requests {from_location_id, to_location_id, lines, note?}
 *  POST stock-requests/{id}/approve {lines?} · POST stock-requests/{id}/send {lines?} · POST stock-requests/{id}/cancel
 *  GET stock-transfers/{id} · POST stock-transfers/{id}/receive {lines?: [{stock_item_id, received_quantity}]}
 */
class StockRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockRequestService $requests, private readonly TransferService $transfers)
    {
    }

    private function cid(Request $request): int
    {
        return (int) $request->user()->company_id;
    }

    private function scope(Request $request): ?int
    {
        return StoreScope::forUser($request->user(), $request->attributes->get('company') ?? Company::withoutGlobalScopes()->find($request->user()->company_id));
    }

    /** A request of this shop the member may see (their store's, when scoped), else null. */
    private function visible(Request $request, $id): ?object
    {
        $r = DB::table('stock_requests')->where('company_id', $this->cid($request))->where('id', (int) $id)->first(['id', 'from_location_id', 'to_location_id']);
        $own = $this->scope($request);
        if ($r === null || ($own !== null && ! in_array($own, [(int) $r->from_location_id, (int) $r->to_location_id], true))) {
            return null;
        }

        return $r;
    }

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', 'in:'.implode(',', array_keys(StockRequestService::STATUSES))], 'q' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $own = $this->scope($request);
        $term = trim((string) ($data['q'] ?? ''));
        $page = DB::table('stock_requests as r')->where('r.company_id', $this->cid($request))
            ->join('locations as f', 'f.id', '=', 'r.from_location_id')->join('locations as to', 'to.id', '=', 'r.to_location_id')
            ->leftJoin('admin_users as u', 'u.id', '=', 'r.requested_by')
            ->when($own !== null, fn ($q) => $q->where(fn ($w) => $w->where('r.from_location_id', $own)->orWhere('r.to_location_id', $own)))
            ->when(! empty($data['status']), fn ($q) => $q->where('r.status', $data['status']))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('r.number', 'like', "%{$term}%")->orWhere('r.notes', 'like', "%{$term}%")))
            ->addSelect(['r.id', 'r.number', 'r.status', 'r.from_location_id', 'r.to_location_id', 'r.stock_transfer_id', 'r.notes', 'r.created_at', 'r.sent_at', 'r.received_at',
                'f.name as from_name', 'to.name as to_name', 'u.name as requested_by_name',
                'lines' => DB::table('stock_request_items')->selectRaw('COUNT(*)')->whereColumn('stock_request_id', 'r.id'),
                'quantity' => DB::table('stock_request_items')->selectRaw('COALESCE(SUM(quantity), 0)')->whereColumn('stock_request_id', 'r.id')])
            ->orderByRaw("FIELD(r.status, 'requested', 'approved', 'sent') = 0")->orderByDesc('r.id')
            ->paginate((int) ($data['per_page'] ?? 20));
        $rows = collect($page->items())->map(fn ($r) => (array) $r + ['status_label' => StockRequestService::STATUSES[$r->status] ?? $r->status,
            'lines' => (int) $r->lines, 'quantity' => (float) $r->quantity])->all();

        return $this->paginated($page, $rows, 'Stock requests.');
    }

    public function show(Request $request, $id)
    {
        if ($this->visible($request, $id) === null) {
            return $this->notFound('Stock request not found.');
        }

        return $this->success($this->requests->find($this->cid($request), (int) $id), 'Stock request.');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'integer'], 'to_location_id' => ['required', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:'.StockRequestService::MAX_LINES],
            'lines.*.stock_item_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->step(function () use ($request, $data) {
            $id = $this->requests->request($this->cid($request), (int) $request->user()->id, (int) $data['from_location_id'], (int) $data['to_location_id'],
                $data['lines'], $data['note'] ?? null, $this->scope($request));

            return $this->created($this->requests->find($this->cid($request), $id), 'Stock asked for.');
        });
    }

    public function approve(Request $request, $id)
    {
        return $this->withQuantities($request, $id, fn (?array $q) => $this->requests->approve($this->cid($request), (int) $request->user()->id, (int) $id, $q, $this->scope($request)),
            'Approved. Send it when it is packed.');
    }

    public function send(Request $request, $id)
    {
        return $this->withQuantities($request, $id, fn (?array $q) => $this->requests->send($this->cid($request), (int) $request->user()->id, (int) $id, $q, $this->scope($request)),
            'Sent. It shows as on the way until the store receives it.');
    }

    public function cancel(Request $request, $id)
    {
        if ($this->visible($request, $id) === null) {
            return $this->notFound('Stock request not found.');
        }

        return $this->step(function () use ($request, $id) {
            $this->requests->cancel($this->cid($request), (int) $request->user()->id, (int) $id, $this->scope($request));

            return $this->success($this->requests->find($this->cid($request), (int) $id), 'Request cancelled.');
        });
    }

    /** One transfer with its lines: sent and (once received) received quantities. */
    public function transfer(Request $request, $id)
    {
        $t = $this->visibleTransfer($request, $id);
        if ($t === null) {
            return $this->notFound('Transfer not found.');
        }
        $row = DB::table('stock_transfers as t')->where('t.id', $t->id)->join('locations as f', 'f.id', '=', 't.from_location_id')->join('locations as to', 'to.id', '=', 't.to_location_id')
            ->leftJoin('admin_users as u', 'u.id', '=', 't.created_by_id')->first(['t.*', 'f.name as from_name', 'to.name as to_name', 'u.name as by']);
        $cols = ['i.id', 'i.stock_item_id', 'p.name', 'p.barcode', 'i.quantity'];
        if (TransferService::transitReady()) {
            $cols[] = 'i.received_quantity';
        }
        $items = DB::table('stock_transfer_items as i')->join('stock_items as p', 'p.id', '=', 'i.stock_item_id')->where('i.stock_transfer_id', $t->id)->orderBy('i.id')->get($cols);

        return $this->success(['transfer' => $row, 'items' => $items], 'Transfer.');
    }

    /**
     * The receiving store takes delivery of a transfer in transit. A transfer made for a stock request is received
     * through StockRequestService (the request becomes "received" too); any other through TransferService.
     * Without `lines` everything sent arrived; with them, a product left out arrived as 0.
     */
    public function receive(Request $request, $id)
    {
        $data = $request->validate(['lines' => ['nullable', 'array', 'max:'.StockRequestService::MAX_LINES],
            'lines.*.stock_item_id' => ['required', 'integer'], 'lines.*.received_quantity' => ['required', 'numeric', 'min:0', 'max:1000000']]);
        $t = $this->visibleTransfer($request, $id);
        if ($t === null) {
            return $this->notFound('Transfer not found.');
        }
        $received = null;
        if (! empty($data['lines'])) {
            $received = [];
            foreach ($data['lines'] as $l) {
                $pid = (int) $l['stock_item_id'];
                $received[$pid] = round((float) ($received[$pid] ?? 0) + (float) $l['received_quantity'], 3);
            }
        }
        $scope = $this->scope($request);

        return $this->step(function () use ($request, $t, $received, $scope) {
            $cid = $this->cid($request);
            $uid = (int) $request->user()->id;
            $requestId = $t->stock_request_id ?? null;
            if ($requestId) {
                $this->requests->receive($cid, $uid, (int) $requestId, $received, $scope);
            } else {
                if ($scope !== null && (int) $t->to_location_id !== $scope) {
                    throw BusinessRuleException::make('other_store', 'Only the store it was sent to can receive this stock.');
                }
                $this->transfers->receive($cid, $uid, (int) $t->id, $received);
            }

            return $this->transfer($request, $t->id)->setStatusCode(200);
        }, 'Received. The stock is on the shelf at the store.');
    }

    // ── helpers ──────────────────────────────────────────────

    private function visibleTransfer(Request $request, $id): ?object
    {
        $t = DB::table('stock_transfers')->where('company_id', $this->cid($request))->where('id', (int) $id)->first();
        $own = $this->scope($request);
        if ($t === null || ($own !== null && ! in_array($own, [(int) $t->from_location_id, (int) $t->to_location_id], true))) {
            return null;
        }

        return $t;
    }

    /** approve / send: optional lines [{stock_item_id, quantity}] as the service's stock_item_id => quantity. */
    private function withQuantities(Request $request, $id, callable $step, string $done): JsonResponse
    {
        $data = $request->validate(['lines' => ['nullable', 'array', 'max:'.StockRequestService::MAX_LINES],
            'lines.*.stock_item_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'min:0', 'max:1000000']]);
        if ($this->visible($request, $id) === null) {
            return $this->notFound('Stock request not found.');
        }
        $quantities = null;
        if (! empty($data['lines'])) {
            $quantities = [];
            foreach ($data['lines'] as $l) {
                $quantities[(int) $l['stock_item_id']] = $l['quantity'];
            }
        }

        return $this->step(function () use ($request, $id, $step, $quantities, $done) {
            $step($quantities);

            return $this->success($this->requests->find($this->cid($request), (int) $id), $done);
        });
    }

    private function step(callable $fn, ?string $message = null): JsonResponse
    {
        try {
            $res = $fn();
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }
        if ($message !== null && $res instanceof JsonResponse) {
            $payload = $res->getData(true);
            $payload['message'] = $message;
            $res->setData($payload);
        }

        return $res;
    }
}

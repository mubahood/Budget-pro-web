<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Services\Shop\PurchaseOrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase orders (plan A5, P4-1).
 *  POST purchase-orders { supplier_id?, expected_date?, notes?, items: [{stock_item_id, quantity, unit_cost?}] }
 *  POST purchase-orders/{id}/send { via_api? } → { text, whatsapp_url }
 *  POST purchase-orders/{id}/receive { items: [{purchase_order_item_id, quantity, unit_cost?}], amount_paid?, payment_method?, invoice_ref?, received_on?, client_uuid? }
 *  POST purchase-orders/{id}/cancel
 */
class PurchaseOrderController extends BaseCrudController
{
    protected string $modelClass = PurchaseOrder::class;

    protected string $resourceName = 'Purchase order';

    protected array $listWith = ['supplier'];

    protected array $showWith = ['supplier', 'items.product'];

    protected array $filterable = ['supplier_id', 'status'];

    protected string $optionLabel = 'number';

    public function __construct(private readonly PurchaseOrderService $orders)
    {
    }

    private function lines(Request $request, int $companyId, bool $required = true): array
    {
        return $request->validate([
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => [$required ? 'required' : 'sometimes', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    protected function transform(Model $model)
    {
        /** @var PurchaseOrder $model */
        return $model->setAttribute('progress', $this->orders->progress($model));
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId($request);
        $data = $this->lines($request, $companyId);
        try {
            $po = $this->orders->create($companyId, (int) $request->user()->id, $data['items'], $data['supplier_id'] ?? null, $data['expected_date'] ?? null, $data['notes'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created($this->transform($po->load(['supplier', 'items.product'])), 'Purchase order saved as draft.');
    }

    public function update(Request $request, $id)
    {
        $po = $this->findOwned($request, $id);
        if (! $po instanceof PurchaseOrder) {
            return $this->notFound('Purchase order not found.');
        }
        $data = $this->lines($request, $this->companyId($request));
        try {
            $po = $this->orders->update($po, $data['items'], $data['supplier_id'] ?? null, $data['expected_date'] ?? null, $data['notes'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($this->transform($po->load(['supplier', 'items.product'])), 'Purchase order updated.');
    }

    public function destroy(Request $request, $id)
    {
        $po = $this->findOwned($request, $id);
        if (! $po instanceof PurchaseOrder) {
            return $this->notFound('Purchase order not found.');
        }
        if ($po->status !== 'draft') {
            return $this->error('Only drafts can be deleted. Cancel the order instead.', 422, ['code' => 'po_not_draft']);
        }
        $po->items()->delete();
        $po->forceDelete(); // drafts leave no trace; sent orders are cancelled instead

        return $this->success(null, 'Draft deleted.');
    }

    public function send(Request $request, $id)
    {
        $po = $this->findOwned($request, $id);
        if (! $po instanceof PurchaseOrder) {
            return $this->notFound('Purchase order not found.');
        }
        try {
            $r = $this->orders->send($po, $request->boolean('via_api'));
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success(['order' => $this->transform($r['order']), 'text' => $r['text'], 'whatsapp_url' => $r['whatsapp_url']], 'Order marked as sent.');
    }

    public function receive(Request $request, $id)
    {
        $po = $this->findOwned($request, $id);
        if (! $po instanceof PurchaseOrder) {
            return $this->notFound('Purchase order not found.');
        }
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'invoice_ref' => ['nullable', 'string', 'max:80'],
            'received_on' => ['nullable', 'date'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);
        try {
            $grn = $this->orders->receive($po, (int) $request->user()->id, $data['items'], (float) ($data['amount_paid'] ?? 0), $data['payment_method'] ?? 'cash',
                $data['invoice_ref'] ?? null, $data['received_on'] ?? null, $data['client_uuid'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(['order' => $this->transform($po->fresh(['supplier', 'items.product'])), 'goods_receipt' => $grn->load('items')], 'Stock received.');
    }

    public function cancel(Request $request, $id)
    {
        $po = $this->findOwned($request, $id);
        if (! $po instanceof PurchaseOrder) {
            return $this->notFound('Purchase order not found.');
        }
        try {
            $po = $this->orders->cancel($po);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($this->transform($po->load(['supplier', 'items.product'])), 'Order closed.');
    }
}

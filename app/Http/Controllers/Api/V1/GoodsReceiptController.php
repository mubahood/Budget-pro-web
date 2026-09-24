<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\GoodsReceipt;
use App\Services\Shop\GoodsReceiptService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Receive stock (GRN-lite, P2-7).
 *  POST goods-receipts { supplier_id?, invoice_ref?, amount_paid?, payment_method?, received_on?, client_uuid?,
 *                        items:[{stock_item_id, quantity, unit_cost}] }
 */
class GoodsReceiptController extends BaseCrudController
{
    protected string $modelClass = GoodsReceipt::class;

    protected string $resourceName = 'Goods receipt';

    protected array $listWith = ['supplier'];

    protected array $showWith = ['supplier', 'items.product'];

    protected array $filterable = ['supplier_id', 'received_on'];

    protected string $optionLabel = 'number';

    public function store(Request $request)
    {
        $companyId = $this->companyId($request);
        $data = $request->validate([
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'invoice_ref' => ['nullable', 'string', 'max:80'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'received_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);
        try {
            $grn = (new GoodsReceiptService())->receive($companyId, (int) $request->user()->id, $data['items'], $data['supplier_id'] ?? null, $data['invoice_ref'] ?? null,
                (float) ($data['amount_paid'] ?? 0), $data['payment_method'] ?? 'cash', $data['received_on'] ?? null, $data['client_uuid'] ?? null, $data['notes'] ?? null, $request->header('X-Device-Id'));
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created($grn->load(['supplier', 'items.product']), 'Stock received.');
    }

    public function update(Request $request, $id)
    {
        return $this->error('Goods receipts are final. Record a stock adjustment to correct them.', 422, ['code' => 'immutable_document']);
    }

    public function destroy(Request $request, $id)
    {
        return $this->error('Goods receipts are final. Record a stock adjustment to correct them.', 422, ['code' => 'immutable_document']);
    }
}

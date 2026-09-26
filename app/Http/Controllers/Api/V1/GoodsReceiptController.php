<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\GoodsReceipt;
use App\Services\Shop\GoodsReceiptService;
use Illuminate\Http\Request;

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
        $data = $request->validate(\App\Support\Rules\GoodsReceiptRules::rules($companyId));
        try {
            $grn = (new GoodsReceiptService())->receive($companyId, (int) $request->user()->id, $data['items'], $data['supplier_id'] ?? null, $data['invoice_ref'] ?? null,
                (float) ($data['amount_paid'] ?? 0), $data['payment_method'] ?? 'cash', $data['received_on'] ?? null, $data['client_uuid'] ?? null, $data['notes'] ?? null, $request->header('X-Device-Id'), null, $data['location_id'] ?? null);
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

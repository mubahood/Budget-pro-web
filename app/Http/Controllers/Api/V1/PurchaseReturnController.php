<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseReturn;
use App\Services\Shop\PurchaseReturnService;
use Illuminate\Http\Request;

/** Returns to a supplier (plan A5, P4-1): POST { supplier_id?, goods_receipt_id?, reason?, refund_amount?, refund_method?, items: [{stock_item_id, quantity, unit_cost?}] } */
class PurchaseReturnController extends BaseCrudController
{
    protected string $modelClass = PurchaseReturn::class;

    protected string $resourceName = 'Purchase return';

    protected array $showWith = ['items'];

    protected array $filterable = ['supplier_id'];

    protected string $optionLabel = 'number';

    public function store(Request $request)
    {
        $companyId = $this->companyId($request);
        $data = $request->validate(\App\Support\Rules\PurchaseReturnRules::rules($companyId));
        try {
            $ret = (new PurchaseReturnService())->create($companyId, (int) $request->user()->id, $data['items'], $data['supplier_id'] ?? null, $data['reason'] ?? null,
                (float) ($data['refund_amount'] ?? 0), $data['refund_method'] ?? 'cash', $data['goods_receipt_id'] ?? null, $data['returned_on'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created($ret, 'Return recorded.');
    }

    public function update(Request $request, $id)
    {
        return $this->error('Returns are final. Record a new movement to correct them.', 422, ['code' => 'immutable_document']);
    }

    public function destroy(Request $request, $id)
    {
        return $this->update($request, $id);
    }
}

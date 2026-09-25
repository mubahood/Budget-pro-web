<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseReturn;
use App\Services\Shop\PurchaseReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $data = $request->validate([
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'goods_receipt_id' => ['nullable', Rule::exists('goods_receipts', 'id')->where('company_id', $companyId)],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'refund_method' => ['nullable', 'string', 'max:30'],
            'returned_on' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
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

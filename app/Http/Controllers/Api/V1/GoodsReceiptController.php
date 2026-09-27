<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\GoodsReceipt;
use App\Services\Shop\GoodsReceiptService;
use Illuminate\Http\Request;

/**
 * Receive stock (GRN-lite, P2-7).
 *  POST goods-receipts { supplier_id?, invoice_ref?, amount_paid?, payment_method?, received_on?, client_uuid?,
 *                        items:[{stock_item_id, quantity, unit_cost, batch_number?, expiry_date?}],
 *                        landed_costs?:[{label, amount}], landed_split?: value|quantity }
 * Landed costs are applied only with the shop's `landed_cost` feature on (LandedCost::forReceipt); the
 * response's `landed_cost_total` says what was applied (0 when none).
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
        $data = $request->validate(\App\Support\Rules\GoodsReceiptRules::rules($companyId) + self::landedRules());
        try {
            $grn = (new GoodsReceiptService())->receive($companyId, (int) $request->user()->id, $data['items'], $data['supplier_id'] ?? null, $data['invoice_ref'] ?? null,
                (float) ($data['amount_paid'] ?? 0), $data['payment_method'] ?? 'cash', $data['received_on'] ?? null, $data['client_uuid'] ?? null, $data['notes'] ?? null, $request->header('X-Device-Id'), null, $data['location_id'] ?? null,
                self::landedOptions($data));
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(self::withLanded($grn->load(['supplier', 'items.product'])), 'Stock received.');
    }

    /** The optional landed-cost fields of a receipt (goods-receipts and purchase-orders/{id}/receive), as the web form validates them. */
    public static function landedRules(): array
    {
        return [
            'landed_costs' => ['nullable', 'array', 'max:10'],
            'landed_costs.*.label' => ['nullable', 'string', 'max:60'],
            'landed_costs.*.amount' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'landed_split' => ['nullable', 'in:'.implode(',', \App\Services\Shop\LandedCost::SPLITS)],
        ];
    }

    /** GoodsReceiptService $options from the validated request ([] when no extra costs were sent). */
    public static function landedOptions(array $data): array
    {
        $costs = array_values(array_filter($data['landed_costs'] ?? [], fn ($c) => (float) ($c['amount'] ?? 0) > 0));

        return $costs === [] ? [] : ['landed_costs' => $costs, 'landed_split' => $data['landed_split'] ?? 'value'];
    }

    /** The receipt with `landed_cost_total` (what was spread over the lines; 0 when none) always present. */
    public static function withLanded(GoodsReceipt $grn): array
    {
        $out = $grn->toArray();
        $out['landed_cost_total'] = round((float) ($grn->getAttribute('landed_cost_total') ?? 0), 2);
        if (is_string($out['landed_costs'] ?? null)) {
            $out['landed_costs'] = json_decode($out['landed_costs'], true);
        }

        return $out;
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

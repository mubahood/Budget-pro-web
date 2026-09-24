<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\StockTake;
use App\Services\Shop\StockTakeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stock count sessions (P2-7).
 *  POST stock-takes { name, stock_category_id? } · POST stock-takes/{id}/counts { counts:[{stock_item_id, counted_quantity}] }
 *  POST stock-takes/{id}/post
 */
class StockTakeController extends BaseCrudController
{
    protected string $modelClass = StockTake::class;

    protected string $resourceName = 'Stock take';

    protected array $showWith = ['items.product'];

    protected array $filterable = ['status'];

    protected string $optionLabel = 'name';

    public function store(Request $request)
    {
        $companyId = $this->companyId($request);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'stock_category_id' => ['nullable', Rule::exists('stock_categories', 'id')->where('company_id', $companyId)],
            'client_uuid' => ['nullable', 'uuid'],
        ]);
        $take = (new StockTakeService())->create($companyId, (int) $request->user()->id, $data['name'] ?? '', $data['stock_category_id'] ?? null, $data['client_uuid'] ?? null, $request->header('X-Device-Id'));

        return $this->created($take, 'Stock count started.');
    }

    public function counts(Request $request, $id)
    {
        /** @var StockTake|null $take */
        $take = $this->findOwned($request, $id);
        if ($take === null) {
            return $this->notFound('Stock take not found.');
        }
        $data = $request->validate(['counts' => ['required', 'array', 'min:1'], 'counts.*.stock_item_id' => ['required', 'integer'], 'counts.*.counted_quantity' => ['required', 'numeric', 'min:0']]);
        try {
            $take = (new StockTakeService())->count($take, $data['counts']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($take, 'Counts saved.');
    }

    public function post(Request $request, $id)
    {
        /** @var StockTake|null $take */
        $take = $this->findOwned($request, $id);
        if ($take === null) {
            return $this->notFound('Stock take not found.');
        }
        try {
            $take = (new StockTakeService())->post($take, (int) $request->user()->id);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($take->load('items.product'), 'Stock count posted; on-hand now matches the count.');
    }

    public function update(Request $request, $id)
    {
        return $this->error('Record counts with POST stock-takes/{id}/counts.', 422, ['code' => 'use_counts']);
    }
}

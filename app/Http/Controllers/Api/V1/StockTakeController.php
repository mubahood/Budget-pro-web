<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\StockTake;
use App\Services\Shop\StockTakeService;
use Illuminate\Http\Request;

/**
 * Stock count sessions (P2-7).
 *  POST stock-takes { name, stock_category_id?, shelf_location? } · POST stock-takes/{id}/counts { counts:[{stock_item_id, counted_quantity}] }
 *  POST stock-takes/{id}/recount { counts } · POST stock-takes/{id}/post · GET stock-takes/shelf-locations
 *
 * Aisle counts (the shop's `aisle_counts` feature, StockTakeService): a count can cover one shelf location, and a
 * count far from the system is flagged `needs_recount`; the next count of that product (counts or recount) is the
 * recount, which stands. Posting refuses while a line waits (422 recount_needed).
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
        $data = $request->validate(\App\Support\Rules\StockTakeRules::rules($companyId) + ['shelf_location' => ['nullable', 'string', 'max:40']]);
        $take = (new StockTakeService())->create($companyId, (int) $request->user()->id, $data['name'] ?? '', $data['stock_category_id'] ?? null, $data['client_uuid'] ?? null, $request->header('X-Device-Id'),
            $data['shelf_location'] ?? null);

        return $this->created($take, 'Stock count started.');
    }

    /** GET stock-takes/shelf-locations — the shelf locations in use, for the aisle picker. */
    public function shelfLocations(Request $request)
    {
        return $this->success(StockTakeService::shelfLocations($this->companyId($request)), 'Shelf locations.', 200, ['aisle_counts' => StockTakeService::aisleCounts($this->companyId($request))]);
    }

    /**
     * POST stock-takes/{id}/recount { counts } — the second count of products flagged `needs_recount`; it stands
     * and clears the flag (StockTakeService::count). A product not waiting for a recount answers 422 not_flagged.
     */
    public function recount(Request $request, $id)
    {
        /** @var StockTake|null $take */
        $take = $this->findOwned($request, $id);
        if ($take === null) {
            return $this->notFound('Stock take not found.');
        }
        $data = $request->validate(\App\Support\Rules\StockTakeRules::countRules());
        $flagged = \App\Models\StockTakeItem::withoutGlobalScopes()->where('stock_take_id', $take->id)->where('needs_recount', true)->pluck('stock_item_id')->map(fn ($i) => (int) $i)->all();
        foreach ($data['counts'] as $c) {
            if (! in_array((int) $c['stock_item_id'], $flagged, true)) {
                return $this->error('That product is not waiting for a recount. Save it with counts instead.', 422, ['code' => 'not_flagged', 'stock_item_id' => (int) $c['stock_item_id']]);
            }
        }

        return $this->saveCounts($take, $data['counts'], 'Recount saved.');
    }

    public function counts(Request $request, $id)
    {
        /** @var StockTake|null $take */
        $take = $this->findOwned($request, $id);
        if ($take === null) {
            return $this->notFound('Stock take not found.');
        }
        $data = $request->validate(\App\Support\Rules\StockTakeRules::countRules());

        return $this->saveCounts($take, $data['counts'], 'Counts saved.');
    }

    /** The take with its lines (each with `needs_recount`) and `recount_needed`: the product ids still waiting for a recount. */
    private function saveCounts(StockTake $take, array $counts, string $message)
    {
        try {
            $take = (new StockTakeService())->count($take, $counts);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }
        $out = $take->toArray();
        $out['items'] = $take->items->map(fn ($i) => $i->toArray() + ['needs_recount' => (bool) ($i->needs_recount ?? false)])->all();
        $out['recount_needed'] = $take->items->filter(fn ($i) => (bool) ($i->needs_recount ?? false))->pluck('stock_item_id')->map(fn ($i) => (int) $i)->values()->all();

        return $this->success($out, $message);
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

    /** POST stock-takes/{id}/cancel — drop a draft count (nothing moves). */
    public function cancel(Request $request, $id)
    {
        /** @var StockTake|null $take */
        $take = $this->findOwned($request, $id);
        if ($take === null) {
            return $this->notFound('Stock take not found.');
        }
        try {
            $take = (new StockTakeService())->cancel($take);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($take, 'Stock count cancelled.');
    }

    public function update(Request $request, $id)
    {
        return $this->error('Record counts with POST stock-takes/{id}/counts.', 422, ['code' => 'use_counts']);
    }
}

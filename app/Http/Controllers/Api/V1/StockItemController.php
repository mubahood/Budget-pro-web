<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StockItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class StockItemController extends BaseCrudController
{
    protected string $modelClass = StockItem::class;

    protected string $resourceName = 'Stock item';

    // current_quantity is derived from original_quantity by the model and immutable after create.
    protected array $writable = \App\Support\Rules\StockItemRules::WRITABLE;

    protected array $searchable = ['name', 'sku', 'barcode'];

    protected array $sortable = ['id', 'name', 'sku', 'selling_price', 'current_quantity', 'created_at', 'updated_at'];

    protected array $filterable = ['stock_sub_category_id', 'stock_category_id', 'is_active', 'track_stock', 'unit_id'];

    protected array $listWith = ['stockSubCategory', 'stockCategory'];

    protected array $showWith = ['stockSubCategory', 'stockCategory', 'createdBy'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return \App\Support\Rules\StockItemRules::rules($this->companyId($request), $existing?->getKey(), $existing !== null);
    }

    /**
     * Barcode/SKU lookup for POS scanning.
     */
    public function byBarcode(Request $request, string $code)
    {
        $companyId = $this->companyId($request);
        $extra = \App\Models\ProductBarcode::withoutGlobalScopes()->where('company_id', $companyId)->where('barcode', $code)->where('is_deleted', 0)->first();
        $item = $this->scopedQuery($request)
            ->where(function ($q) use ($code, $extra) {
                $q->where('barcode', $code)->orWhere('sku', $code);
                if ($extra) {
                    $q->orWhere('stock_items.id', $extra->stock_item_id);
                }
            })
            ->with($this->showWith)
            ->first();

        if ($item === null) {
            return $this->notFound('No product matches that barcode or SKU.');
        }
        $payload = $this->transform($item);
        $payload->setAttribute('scanned_unit_id', $extra?->unit_id);

        return $this->success($payload, 'Product found.');
    }

    protected function transform(Model $model)
    {
        if (! \App\Services\Team\Permissions::can(request()->user(), 'view_cost')) {
            $model->makeHidden(['buying_price']);
        }

        return parent::transform($model);
    }

    /** Plan limit on products (A10): refused online with 422 plan_limit_reached. */
    public function store(Request $request)
    {
        try {
            (new \App\Services\Billing\Quotas())->assertCanAdd(\App\Models\Company::withoutGlobalScopes()->findOrFail($this->companyId($request)), 'products');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        // Opening stock and cost default to 0 like the table does: without it the model copied a null
        // into current_quantity and the insert failed (500) for a product sent without opening stock.
        foreach (['original_quantity', 'buying_price'] as $field) {
            if ($request->input($field) === null) {
                $request->merge([$field => 0]);
            }
        }

        return parent::store($request);
    }

    protected function optionLabel(Model $model): string
    {
        return trim(($model->getAttribute('sku') ? $model->getAttribute('sku').' — ' : '').$model->getAttribute('name'));
    }
}

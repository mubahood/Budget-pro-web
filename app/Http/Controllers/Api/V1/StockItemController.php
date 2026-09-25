<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StockItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockItemController extends BaseCrudController
{
    protected string $modelClass = StockItem::class;

    protected string $resourceName = 'Stock item';

    // current_quantity is derived from original_quantity by the model and immutable after create.
    protected array $writable = ['stock_sub_category_id', 'name', 'description', 'image', 'barcode', 'sku', 'buying_price', 'selling_price', 'original_quantity', 'min_stock', 'allow_negative_stock', 'track_stock', 'is_active', 'unit_id'];

    protected array $searchable = ['name', 'sku', 'barcode'];

    protected array $sortable = ['id', 'name', 'sku', 'selling_price', 'current_quantity', 'created_at', 'updated_at'];

    protected array $filterable = ['stock_sub_category_id', 'stock_category_id', 'is_active', 'track_stock', 'unit_id'];

    protected array $listWith = ['stockSubCategory', 'stockCategory'];

    protected array $showWith = ['stockSubCategory', 'stockCategory', 'createdBy'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = (int) $request->user()->company_id;

        return [
            'stock_sub_category_id' => [
                $existing ? 'sometimes' : 'required',
                Rule::exists('stock_sub_categories', 'id')->where('company_id', $companyId),
            ],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:100'],
            'buying_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'original_quantity' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'track_stock' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('company_id', $companyId)],
        ];
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

        return parent::store($request);
    }

    protected function optionLabel(Model $model): string
    {
        return trim(($model->getAttribute('sku') ? $model->getAttribute('sku').' — ' : '').$model->getAttribute('name'));
    }
}

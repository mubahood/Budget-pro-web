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
    protected array $writable = ['stock_sub_category_id', 'name', 'description', 'image', 'barcode', 'sku', 'buying_price', 'selling_price', 'original_quantity'];
    protected array $searchable = ['name', 'sku', 'barcode'];
    protected array $sortable = ['id', 'name', 'sku', 'selling_price', 'current_quantity', 'created_at', 'updated_at'];
    protected array $filterable = ['stock_sub_category_id', 'stock_category_id'];
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
        ];
    }

    /**
     * Barcode/SKU lookup for POS scanning.
     */
    public function byBarcode(Request $request, string $code)
    {
        $item = $this->scopedQuery($request)
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('sku', $code);
            })
            ->with($this->showWith)
            ->first();

        if ($item === null) {
            return $this->notFound('No product matches that barcode or SKU.');
        }

        return $this->success($this->transform($item), 'Product found.');
    }

    protected function optionLabel(Model $model): string
    {
        return trim(($model->sku ? $model->sku.' — ' : '').$model->name);
    }
}

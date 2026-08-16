<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StockSubCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockSubCategoryController extends BaseCrudController
{
    protected string $modelClass = StockSubCategory::class;
    protected string $resourceName = 'Stock sub-category';

    protected array $writable = ['stock_category_id', 'name', 'description', 'status', 'image', 'measurement_unit', 'reorder_level'];
    protected array $searchable = ['name', 'description'];
    protected array $sortable = ['id', 'name', 'created_at', 'updated_at'];
    protected array $filterable = ['status', 'stock_category_id', 'in_stock'];
    protected array $listWith = ['stockCategory'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = (int) $request->user()->company_id;

        return [
            'stock_category_id' => [
                $existing ? 'sometimes' : 'required',
                Rule::exists('stock_categories', 'id')->where('company_id', $companyId),
            ],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', 'max:50'],
            'image' => ['nullable', 'string', 'max:255'],
            'measurement_unit' => ['nullable', 'string', 'max:50'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}

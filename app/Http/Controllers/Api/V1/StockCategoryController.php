<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StockCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class StockCategoryController extends BaseCrudController
{
    protected string $modelClass = StockCategory::class;
    protected string $resourceName = 'Stock category';

    // buying_price/selling_price/expected_profit/earned_profit are rollups the model computes.
    protected array $writable = ['name', 'description', 'status', 'image'];
    protected array $searchable = ['name', 'description'];
    protected array $sortable = ['id', 'name', 'created_at', 'updated_at'];
    protected array $filterable = ['status'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', 'max:50'],
            'image' => ['nullable', 'string', 'max:255'],
        ];
    }
}

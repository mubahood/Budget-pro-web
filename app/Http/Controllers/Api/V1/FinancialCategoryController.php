<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinancialCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class FinancialCategoryController extends BaseCrudController
{
    protected string $modelClass = FinancialCategory::class;
    protected string $resourceName = 'Financial category';

    protected array $writable = ['name', 'description'];
    protected array $searchable = ['name', 'description'];
    protected array $sortable = ['id', 'name', 'created_at'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

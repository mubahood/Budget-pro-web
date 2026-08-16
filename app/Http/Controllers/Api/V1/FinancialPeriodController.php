<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinancialPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialPeriodController extends BaseCrudController
{
    protected string $modelClass = FinancialPeriod::class;
    protected string $resourceName = 'Financial period';

    protected array $writable = ['name', 'start_date', 'end_date', 'status', 'description'];
    protected array $searchable = ['name', 'description'];
    protected array $sortable = ['id', 'name', 'start_date', 'end_date', 'created_at'];
    protected array $filterable = ['status'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['Active', 'Closed', 'Inactive'])],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BudgetItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetItemController extends BaseCrudController
{
    protected string $modelClass = BudgetItem::class;
    protected string $resourceName = 'Budget item';

    // target_amount = unit_price * quantity, and rollups, are computed by the model.
    protected array $writable = ['budget_item_category_id', 'name', 'unit_price', 'quantity', 'approved', 'details', 'priority'];
    protected array $searchable = ['name', 'details'];
    protected array $sortable = ['id', 'name', 'unit_price', 'created_at'];
    protected array $filterable = ['budget_item_category_id', 'budget_program_id', 'approved'];
    protected array $listWith = ['budgetItemCategory', 'budgetProgram'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = (int) $request->user()->company_id;

        return [
            'budget_item_category_id' => [
                $existing ? 'sometimes' : 'required',
                Rule::exists('budget_item_categories', 'id')->where('company_id', $companyId),
            ],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'unit_price' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'quantity' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'approved' => ['nullable', 'string', 'max:20'],
            'details' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', 'string', 'max:50'],
        ];
    }
}

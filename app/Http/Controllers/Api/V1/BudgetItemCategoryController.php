<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BudgetItemCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetItemCategoryController extends BaseCrudController
{
    protected string $modelClass = BudgetItemCategory::class;
    protected string $resourceName = 'Budget category';

    // target_amount / invested_amount / balance / percentage_done are rollups from items.
    protected array $writable = ['budget_program_id', 'name'];
    protected array $searchable = ['name'];
    protected array $sortable = ['id', 'name', 'created_at'];
    protected array $filterable = ['budget_program_id'];
    protected array $listWith = ['budgetProgram'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = (int) $request->user()->company_id;

        return [
            'budget_program_id' => [
                $existing ? 'sometimes' : 'required',
                Rule::exists('budget_programs', 'id')->where('company_id', $companyId),
            ],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
        ];
    }
}

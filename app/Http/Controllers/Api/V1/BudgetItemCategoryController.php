<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BudgetItemCategory;
use App\Services\Budget\BudgetAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetItemCategoryController extends BaseCrudController
{
    /** The people and the account inside a row carry no private details for someone without full access (BudgetAccess::hideNested). */
    protected function transform(\Illuminate\Database\Eloquent\Model $model)
    {
        \App\Services\Budget\BudgetAccess::hideNested($model, request()->user());

        return parent::transform($model);
    }

    protected string $modelClass = BudgetItemCategory::class;

    protected string $resourceName = 'Budget category';

    // target_amount / invested_amount / balance / percentage_done are rollups from items.
    protected array $writable = ['budget_program_id', 'name'];

    protected array $searchable = ['name'];

    protected array $sortable = ['id', 'name', 'created_at'];

    protected array $filterable = ['budget_program_id'];

    protected array $listWith = ['budgetProgram'];

    /** Only the events this person has a seat on (BudgetAccess). */
    protected function scopedQuery(Request $request)
    {
        return BudgetAccess::limitToSeats(parent::scopedQuery($request), $request->user(), $this->table().'.budget_program_id');
    }

    /** A category with lines still in it is not removed from here: move or remove them first, so no line is left without a home. */
    public function destroy(Request $request, $id)
    {
        $model = $this->findOwned($request, $id);
        if ($model !== null && \Illuminate\Support\Facades\DB::table('budget_items')->where('budget_item_category_id', $model->getKey())->where('is_deleted', 0)->exists()) {
            return $this->error('This category still has budget lines. Move or remove them first.', 422, ['code' => 'category_has_items']);
        }

        return parent::destroy($request, $id);
    }

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

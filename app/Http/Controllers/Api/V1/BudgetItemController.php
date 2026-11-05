<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BudgetItem;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\ContributionPayments;
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

    /** Only the events this person has a seat on (BudgetAccess). */
    protected function scopedQuery(Request $request)
    {
        return BudgetAccess::limitToSeats(parent::scopedQuery($request), $request->user(), $this->table().'.budget_program_id');
    }

    /** A line with money on it is not removed from here: reverse its payments first, so nothing paid out vanishes (BudgetPayments). */
    public function destroy(Request $request, $id)
    {
        $model = $this->findOwned($request, $id);
        if ($model !== null && \App\Services\Budget\BudgetPayments::ready()) {
            try {
                (new \App\Services\Budget\BudgetPayments())->assertRemovable((int) $model->getKey(), (int) $model->company_id);
            } catch (\App\Exceptions\BusinessRuleException $e) {
                return $this->error($e->getMessage(), 422, $e->toErrors());
            }
        }

        return parent::destroy($request, $id);
    }

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = (int) $request->user()->company_id;

        return [
            'budget_item_category_id' => [
                $existing ? 'sometimes' : 'required',
                Rule::exists('budget_item_categories', 'id')->where('company_id', $companyId),
            ],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'unit_price' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0', ContributionPayments::exactRule($companyId)],
            'quantity' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'approved' => ['nullable', 'string', 'max:20'],
            'details' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', 'string', 'max:50'],
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContributionRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContributionRecordController extends BaseCrudController
{
    protected string $modelClass = ContributionRecord::class;
    protected string $resourceName = 'Contribution';

    // not_paid_amount is derived by the model.
    protected array $writable = ['budget_program_id', 'treasurer_id', 'name', 'amount', 'paid_amount', 'fully_paid', 'custom_amount', 'custom_paid_amount', 'category_id'];
    protected array $searchable = ['name'];
    protected array $sortable = ['id', 'name', 'amount', 'paid_amount', 'created_at'];
    protected array $filterable = ['budget_program_id', 'treasurer_id', 'fully_paid'];
    protected array $listWith = ['budgetProgram', 'treasurer'];

    public function store(Request $request)
    {
        // The API caller acts as treasurer by default (no admin session in API context).
        if (empty($request->input('treasurer_id'))) {
            $request->merge(['treasurer_id' => (int) $request->user()->id]);
        }

        return parent::store($request);
    }

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = (int) $request->user()->company_id;

        return [
            'budget_program_id' => [
                $existing ? 'sometimes' : 'required',
                Rule::exists('budget_programs', 'id')->where('company_id', $companyId),
            ],
            'treasurer_id' => [
                'nullable',
                Rule::exists('admin_users', 'id')->where('company_id', $companyId),
            ],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'amount' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'fully_paid' => ['nullable', Rule::in(['Yes', 'No'])],
            'custom_amount' => ['nullable', 'numeric', 'min:0'],
            'custom_paid_amount' => ['nullable', 'numeric', 'min:0'],
            'category_id' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * Contribution records are protected from deletion at the model level to preserve
     * the pledge audit trail.
     */
    public function destroy(Request $request, $id)
    {
        return $this->error('Contribution records cannot be deleted to preserve the pledge audit trail.', 422);
    }
}

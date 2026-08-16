<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialRecordController extends BaseCrudController
{
    protected string $modelClass = FinancialRecord::class;
    protected string $resourceName = 'Financial record';

    protected array $writable = ['financial_category_id', 'amount', 'quantity', 'type', 'payment_method', 'recipient', 'description', 'receipt', 'date'];
    protected array $searchable = ['description', 'recipient'];
    protected array $sortable = ['id', 'amount', 'date', 'created_at'];
    protected array $filterable = ['type', 'financial_category_id', 'payment_method'];
    protected array $listWith = ['financial_category'];
    protected array $showWith = ['financial_category', 'createdBy'];
    protected string $optionLabel = 'description';

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = (int) $request->user()->company_id;

        return [
            'financial_category_id' => [
                $existing ? 'sometimes' : 'required',
                Rule::exists('financial_categories', 'id')->where('company_id', $companyId),
            ],
            'amount' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'type' => [$existing ? 'sometimes' : 'required', Rule::in(['Income', 'Expense'])],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'recipient' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
        ];
    }
}

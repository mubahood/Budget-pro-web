<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinancialRecord;
use App\Support\Rules\FinancialRecordRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class FinancialRecordController extends BaseCrudController
{
    protected string $modelClass = FinancialRecord::class;

    protected string $resourceName = 'Financial record';

    protected array $writable = FinancialRecordRules::WRITABLE;

    protected array $searchable = ['description', 'recipient'];

    protected array $sortable = ['id', 'amount', 'date', 'created_at'];

    protected array $filterable = ['type', 'financial_category_id', 'payment_method'];

    protected array $listWith = ['financial_category'];

    protected array $showWith = ['financial_category', 'createdBy'];

    protected string $optionLabel = 'description';

    protected function rules(Request $request, ?Model $existing): array
    {
        return FinancialRecordRules::rules($this->companyId($request), $existing?->getKey(), $existing !== null);
    }
}

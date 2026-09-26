<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinancialPeriod;
use App\Support\Rules\FinancialPeriodRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class FinancialPeriodController extends BaseCrudController
{
    protected string $modelClass = FinancialPeriod::class;

    protected string $resourceName = 'Financial period';

    protected array $writable = FinancialPeriodRules::WRITABLE;

    protected array $searchable = ['name', 'description'];

    protected array $sortable = ['id', 'name', 'start_date', 'end_date', 'created_at'];

    protected array $filterable = ['status'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return FinancialPeriodRules::rules($this->companyId($request), $existing?->getKey(), $existing !== null);
    }
}

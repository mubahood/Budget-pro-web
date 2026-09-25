<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinancialReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Financial report PDFs (v1 replacement for the legacy api/FinancialReport path, P4-8). do_generate=Yes builds the PDF. */
class FinancialReportController extends BaseCrudController
{
    protected string $modelClass = FinancialReport::class;

    protected string $resourceName = 'Financial report';

    protected array $writable = ['type', 'period_type', 'start_date', 'end_date', 'currency', 'include_finance_accounts', 'include_finance_records',
        'inventory_include_categories', 'inventory_include_sub_categories', 'inventory_include_products', 'do_generate'];

    protected array $sortable = ['id', 'start_date', 'end_date', 'created_at'];

    protected array $filterable = ['type', 'period_type'];

    protected function fill(Model $model, array $validated, Request $request): void
    {
        parent::fill($model, $validated, $request);
        $model->setAttribute('user_id', $request->user()->id); // the report is built for this user's company
    }

    protected function rules(Request $request, ?Model $existing): array
    {
        $yesNo = ['nullable', Rule::in(['Yes', 'No'])];

        return [
            'type' => [$existing ? 'sometimes' : 'required', Rule::in(['Financial', 'Inventory'])],
            'period_type' => [$existing ? 'sometimes' : 'required', Rule::in(['Today', 'Yesterday', 'Week', 'Month', 'Last Week', 'Last Month', 'Quarter', 'Last Quarter', 'Last 6 Months', 'Last Year', 'Cycle', 'Year', 'Custom'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'currency' => ['nullable', 'string', 'max:10'],
            'include_finance_accounts' => $yesNo, 'include_finance_records' => $yesNo, 'inventory_include_categories' => $yesNo,
            'inventory_include_sub_categories' => $yesNo, 'inventory_include_products' => $yesNo, 'do_generate' => $yesNo,
        ];
    }
}

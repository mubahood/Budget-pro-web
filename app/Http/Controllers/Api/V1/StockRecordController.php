<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StockRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stock movement ledger. Creating a record runs the model's boot hooks, which
 * validate stock availability, deduct quantity for sales, and post financial
 * records. Records are immutable once created (mirrors the admin behaviour).
 */
class StockRecordController extends BaseCrudController
{
    protected string $modelClass = StockRecord::class;
    protected string $resourceName = 'Stock record';

    protected array $writable = ['stock_item_id', 'type', 'quantity', 'description', 'date'];
    protected array $searchable = ['name', 'sku', 'description'];
    protected array $sortable = ['id', 'created_at', 'quantity', 'total_sales'];
    protected array $filterable = ['type', 'stock_item_id', 'stock_category_id', 'stock_sub_category_id'];
    protected array $listWith = ['stockItem'];
    protected array $showWith = ['stockItem', 'createdBy'];
    protected string $optionLabel = 'name';

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = (int) $request->user()->company_id;

        return [
            'stock_item_id' => [
                'required',
                Rule::exists('stock_items', 'id')->where('company_id', $companyId),
            ],
            'type' => ['required', Rule::in(['Sale', 'Stock In', 'Expired', 'Other'])],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:1000'],
            'date' => ['nullable', 'date'],
        ];
    }

    /**
     * Stock records are an immutable ledger — updates are not allowed.
     */
    public function update(Request $request, $id)
    {
        return $this->error('Stock records are immutable and cannot be edited. Create a correcting entry instead.', 422);
    }
}

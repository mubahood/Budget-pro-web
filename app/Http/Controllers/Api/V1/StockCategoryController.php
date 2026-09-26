<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StockCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class StockCategoryController extends BaseCrudController
{
    protected string $modelClass = StockCategory::class;

    protected string $resourceName = 'Stock category';

    // buying_price/selling_price/expected_profit/earned_profit are rollups the model computes.
    protected array $writable = \App\Support\Rules\StockCategoryRules::WRITABLE;

    protected array $searchable = ['name', 'description'];

    protected array $sortable = ['id', 'name', 'created_at', 'updated_at'];

    protected array $filterable = ['status'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return \App\Support\Rules\StockCategoryRules::rules($this->companyId($request), $existing?->getKey(), $existing !== null);
    }

    /** A category that still holds products (or sub-categories) is refused: they would lose their home. */
    public function destroy(Request $request, $id)
    {
        $model = $this->findOwned($request, $id);
        if ($model === null) {
            return $this->notFound('Stock category not found.');
        }
        try {
            \assert($model instanceof \App\Models\StockCategory);
            \App\Support\Rules\StockCategoryRules::assertDeletable($model);
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return parent::destroy($request, $id);
    }
}

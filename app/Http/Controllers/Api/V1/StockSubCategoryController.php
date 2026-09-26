<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StockSubCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class StockSubCategoryController extends BaseCrudController
{
    protected string $modelClass = StockSubCategory::class;

    protected string $resourceName = 'Stock sub-category';

    protected array $writable = \App\Support\Rules\StockSubCategoryRules::WRITABLE;

    protected array $searchable = ['name', 'description'];

    protected array $sortable = ['id', 'name', 'created_at', 'updated_at'];

    protected array $filterable = ['status', 'stock_category_id', 'in_stock'];

    protected array $listWith = ['stockCategory'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return \App\Support\Rules\StockSubCategoryRules::rules($this->companyId($request), $existing?->getKey(), $existing !== null);
    }

    /** A sub-category that still holds products is refused: they would lose their home. */
    public function destroy(Request $request, $id)
    {
        $model = $this->findOwned($request, $id);
        if ($model === null) {
            return $this->notFound('Stock sub-category not found.');
        }
        try {
            \assert($model instanceof \App\Models\StockSubCategory);
            \App\Support\Rules\StockSubCategoryRules::assertDeletable($model);
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return parent::destroy($request, $id);
    }
}

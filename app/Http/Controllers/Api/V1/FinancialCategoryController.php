<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinancialCategory;
use App\Support\Rules\FinancialCategoryRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class FinancialCategoryController extends BaseCrudController
{
    protected string $modelClass = FinancialCategory::class;

    protected string $resourceName = 'Financial category';

    protected array $writable = FinancialCategoryRules::WRITABLE;

    protected array $searchable = ['name', 'description'];

    protected array $sortable = ['id', 'name', 'created_at'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return FinancialCategoryRules::rules($this->companyId($request), $existing?->getKey(), $existing !== null);
    }

    /** A category still used by entries is kept (set it Inactive instead). */
    public function destroy(Request $request, $id)
    {
        $model = $this->findOwned($request, $id);
        if ($model instanceof \App\Models\FinancialCategory && ($why = FinancialCategoryRules::deletionBlocker($model)) !== null) {
            return $this->error($why, 422, ['code' => 'category_in_use']);
        }

        return parent::destroy($request, $id);
    }
}

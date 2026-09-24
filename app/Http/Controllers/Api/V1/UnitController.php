<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Units of measure (plan A8 `units`). */
class UnitController extends BaseCrudController
{
    protected string $modelClass = Unit::class;

    protected string $resourceName = 'Unit';

    protected array $writable = ['name', 'abbreviation', 'base_unit_id', 'factor'];

    protected array $searchable = ['name', 'abbreviation'];

    protected array $sortable = ['id', 'name', 'factor'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = $this->companyId($request);

        return [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:60'],
            'abbreviation' => [$existing ? 'sometimes' : 'required', 'string', 'max:15'],
            'base_unit_id' => ['nullable', Rule::exists('units', 'id')->where('company_id', $companyId)],
            'factor' => ['nullable', 'numeric', 'min:0.001'],
        ];
    }

    public function destroy(Request $request, $id)
    {
        $unit = $this->findOwned($request, $id);
        if ($unit === null) {
            return $this->notFound('Unit not found.');
        }
        if (\App\Models\StockItem::withoutGlobalScopes()->where('unit_id', $unit->id)->exists()) {
            return $this->error('Products still use this unit.', 422, ['code' => 'unit_in_use']);
        }

        return parent::destroy($request, $id);
    }
}

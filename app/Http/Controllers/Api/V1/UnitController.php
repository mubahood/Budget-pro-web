<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Units of measure (plan A8 `units`). */
class UnitController extends BaseCrudController
{
    protected string $modelClass = Unit::class;

    protected string $resourceName = 'Unit';

    protected array $writable = \App\Support\Rules\UnitRules::WRITABLE;

    protected array $searchable = ['name', 'abbreviation'];

    protected array $sortable = ['id', 'name', 'factor'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return \App\Support\Rules\UnitRules::rules($this->companyId($request), $existing?->getKey(), $existing !== null);
    }

    public function destroy(Request $request, $id)
    {
        $unit = $this->findOwned($request, $id);
        if ($unit === null) {
            return $this->notFound('Unit not found.');
        }
        try {
            \assert($unit instanceof \App\Models\Unit);
            \App\Support\Rules\UnitRules::assertDeletable($unit);
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, ['code' => 'unit_in_use']);
        }

        return parent::destroy($request, $id);
    }
}

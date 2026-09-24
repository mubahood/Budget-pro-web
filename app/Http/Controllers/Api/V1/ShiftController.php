<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\Shift;
use App\Services\Shop\ShiftService;
use Illuminate\Http\Request;

/**
 * Shifts / cash-up (plan A3, P2-8).
 *  GET shifts · GET shifts/current · GET shifts/{id} (with live totals)
 *  POST shifts/open { opening_float, client_uuid? } · POST shifts/{id}/close { counted_cash, notes? }
 */
class ShiftController extends BaseCrudController
{
    protected string $modelClass = Shift::class;

    protected string $resourceName = 'Shift';

    protected array $sortable = ['id', 'opened_at', 'closed_at'];

    protected array $filterable = ['status', 'opened_by_id'];

    protected string $optionLabel = 'number';

    protected function transform(\Illuminate\Database\Eloquent\Model $model)
    {
        /** @var Shift $model */
        $model->setAttribute('totals', (new ShiftService())->totals($model));

        return $model;
    }

    public function current(Request $request)
    {
        $shift = (new ShiftService())->current($this->companyId($request), (int) $request->user()->id, $request->header('X-Device-Id'));

        return $this->success($shift ? $this->transform($shift) : null, $shift ? 'Open shift.' : 'No open shift.');
    }

    public function open(Request $request)
    {
        $data = $request->validate(['opening_float' => ['required', 'numeric', 'min:0'], 'client_uuid' => ['nullable', 'uuid'], 'notes' => ['nullable', 'string', 'max:500']]);
        try {
            $shift = (new ShiftService())->open($this->companyId($request), (int) $request->user()->id, (float) $data['opening_float'], $request->header('X-Device-Id'), $data['client_uuid'] ?? null, $data['notes'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created($this->transform($shift), 'Shift opened.');
    }

    public function close(Request $request, $id)
    {
        /** @var Shift|null $shift */
        $shift = $this->findOwned($request, $id);
        if ($shift === null) {
            return $this->notFound('Shift not found.');
        }
        $data = $request->validate(['counted_cash' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:500']]);
        try {
            $shift = (new ShiftService())->close($shift, (float) $data['counted_cash'], (int) $request->user()->id, $data['notes'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($this->transform($shift), 'Shift closed.');
    }

    public function store(Request $request)
    {
        return $this->open($request);
    }

    public function update(Request $request, $id)
    {
        return $this->error('Shifts change only by opening and closing.', 422, ['code' => 'immutable_shift']);
    }

    public function destroy(Request $request, $id)
    {
        return $this->error('Shifts cannot be deleted.', 422, ['code' => 'immutable_shift']);
    }
}

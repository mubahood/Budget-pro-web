<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\StockRecord;
use App\Services\Shop\StockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stock movement ledger (append-only).
 *
 *  GET    stock-records/types         movement types grouped by direction
 *  POST   stock-records               record a movement (idempotent on client_uuid)
 *  POST   stock-records/{id}/reverse  contra movement (nothing is deleted)
 *  PATCH/DELETE                       refused (422)
 */
class StockRecordController extends BaseCrudController
{
    protected string $modelClass = StockRecord::class;

    protected string $resourceName = 'Stock record';

    protected array $writable = ['stock_item_id', 'type', 'quantity', 'description', 'date', 'selling_price', 'unit_cost', 'client_uuid', 'reason', 'image'];

    protected array $searchable = ['name', 'sku', 'description'];

    protected array $sortable = ['id', 'created_at', 'date', 'quantity', 'total_sales'];

    protected array $filterable = ['type', 'stock_item_id', 'stock_category_id', 'stock_sub_category_id', 'is_reversal', 'date'];

    protected array $listWith = ['stockItem'];

    protected array $showWith = ['stockItem', 'createdBy'];

    protected string $optionLabel = 'name';

    /** Reason codes for adjustments (plan A4). */
    public const REASONS = ['damage', 'expired', 'lost', 'theft', 'internal_use', 'correction', 'gift', 'restock', 'return', 'other'];

    public function types()
    {
        return $this->success(['inbound' => StockService::INBOUND, 'outbound' => StockService::OUTBOUND, 'reasons' => self::REASONS], 'Movement types.');
    }

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = $this->companyId($request);

        return [
            'client_uuid' => ['nullable', 'uuid'],
            'stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'type' => ['required', Rule::in(StockService::types())],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'description' => ['nullable', 'string', 'max:1000'],
            'date' => ['nullable', 'date'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'in:'.implode(',', self::REASONS)],
            'image' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules($request, null));

        try {
            $record = (new StockService())->record($validated + ['created_by_id' => (int) $request->user()->id]);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        $fresh = $this->findOwned($request, $record->getKey(), $this->showWith) ?? $record;
        if ($record->wasReplayed) {
            return $this->success($this->transform($fresh), 'Stock record already recorded (idempotent replay).');
        }

        return $this->created($this->transform($fresh), 'Stock record created successfully.');
    }

    public function reverse(Request $request, $id)
    {
        /** @var StockRecord|null $record */
        $record = $this->findOwned($request, $id);
        if ($record === null) {
            return $this->notFound('Stock record not found.');
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $contra = (new StockService())->reverse($record, $data['reason'] ?? null, (int) $request->user()->id);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created($this->transform($this->findOwned($request, $contra->id, $this->showWith) ?? $contra), 'Movement reversed.');
    }

    public function update(Request $request, $id)
    {
        return $this->error('Stock records are immutable and cannot be edited. Reverse the movement instead.', 422, ['code' => 'immutable_movement']);
    }

    public function destroy(Request $request, $id)
    {
        if ($this->findOwned($request, $id) === null) {
            return $this->notFound('Stock record not found.');
        }

        return $this->error('Stock records cannot be deleted. Reverse the movement instead (POST stock-records/{id}/reverse).', 422, ['code' => 'immutable_movement']);
    }
}

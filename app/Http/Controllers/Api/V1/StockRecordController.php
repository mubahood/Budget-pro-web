<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\StockRecord;
use App\Services\Shop\MovementDocuments;
use App\Services\Shop\StockService;
use App\Support\Rules\StockRecordRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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

    protected array $writable = StockRecordRules::WRITABLE;

    protected array $searchable = ['name', 'sku', 'description'];

    protected array $sortable = ['id', 'created_at', 'date', 'quantity', 'total_sales'];

    protected array $filterable = ['type', 'stock_item_id', 'stock_category_id', 'stock_sub_category_id', 'is_reversal', 'date'];

    protected array $listWith = ['stockItem'];

    protected array $showWith = ['stockItem', 'createdBy'];

    protected string $optionLabel = 'name';

    /** Reason codes for adjustments (plan A4). */
    public const REASONS = StockRecordRules::REASONS;

    public function types()
    {
        return $this->success(['inbound' => StockService::INBOUND, 'outbound' => StockService::OUTBOUND, 'reasons' => self::REASONS], 'Movement types.');
    }

    protected function rules(Request $request, ?Model $existing): array
    {
        return StockRecordRules::rules($this->companyId($request));
    }

    public function store(Request $request)
    {
        $type = (string) $request->input('type');
        if (in_array($type, StockService::types(), true)) {
            $need = StockRecordRules::permissionFor($type);
            if (! \App\Services\Team\Permissions::can($request->user(), $need)) {
                return $this->error('Your role does not allow this stock movement.', 403, ['code' => 'forbidden', 'permission' => $need]);
            }
        }
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
        $data = $request->validate(StockRecordRules::reverseRules());
        // Movements that belong to a document (sale, delivery, transfer, count…) are undone on that document.
        try {
            $contra = (new MovementDocuments())->reverse($record, $data['reason'] ?? null, (int) $request->user()->id);
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

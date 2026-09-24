<?php

namespace App\Services\Sync;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Device;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Models\SyncBatch;
use App\Services\Shop\PaymentService;
use App\Services\Shop\SaleService;
use App\Services\Shop\StockService;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;

/**
 * `POST /sync/push` (plan A.2): every batch is one DB transaction routed
 * through the Phase 0 domain services; batches are idempotent by batch_uuid;
 * event rows are insert-if-absent; a completed offline sale is never rejected
 * for stock (Appendix E) — it is applied and flagged.
 */
class SyncApplier
{
    public function __construct(
        private readonly SaleService $sales = new SaleService(),
        private readonly PaymentService $payments = new PaymentService(),
        private readonly StockService $stock = new StockService(),
        private readonly MasterDataService $master = new MasterDataService(),
        private readonly SyncSerializer $serializer = new SyncSerializer(),
    ) {
    }

    /** @return array{server_time: int, results: array} */
    public function push(Company $company, ?Device $device, int $userId, array $batches): array
    {
        $results = [];
        $hold = $company->accessState() === 'expired' || $company->accessState() === 'inactive';
        foreach ($batches as $batch) {
            $results[] = $this->applyBatch($company, $device, $userId, $batch, $hold);
        }

        return ['server_time' => SyncSequence::nowMs(), 'results' => $results];
    }

    public function applyBatch(Company $company, ?Device $device, int $userId, array $batch, bool $hold = false): array
    {
        $batchUuid = (string) ($batch['batch_uuid'] ?? '');
        $companyId = (int) $company->id;
        $deviceId = $device?->device_id;

        $existing = SyncBatch::where('company_id', $companyId)->where('batch_uuid', $batchUuid)->first();
        if ($existing && ! in_array($existing->status, ['held', 'rejected'], true)) {
            return ($existing->result ?? ['batch_uuid' => $batchUuid, 'status' => $existing->status]) + ['replayed' => true];
        }

        if ($hold) {
            $log = $existing ?? new SyncBatch(['company_id' => $companyId, 'batch_uuid' => $batchUuid]);
            $log->fill(['device_id' => $deviceId, 'user_id' => $userId, 'kind' => $batch['kind'] ?? 'generic', 'status' => 'held', 'ops_count' => count($batch['ops'] ?? []), 'payload' => $batch]);
            $log->save();

            return ['batch_uuid' => $batchUuid, 'status' => 'held', 'code' => 'subscription_expired', 'message' => 'Your subscription has expired. Records are kept safely and will be applied when you renew.'];
        }

        if ($batchUuid === '') {
            return ['batch_uuid' => null, 'status' => 'rejected', 'code' => 'validation', 'message' => 'batch_uuid is required.'];
        }

        try {
            return $this->applyInTransaction($company, $companyId, $deviceId, $userId, $batch, $batchUuid);
        } catch (BatchRejected $e) {
            // Rejections are recorded too, so a replay of the same bad batch answers identically.
            $result = ['batch_uuid' => $batchUuid, 'status' => 'rejected', 'ops' => $e->ops, 'server_seq_max' => SyncSequence::current(), 'assigned' => [], 'derived' => ['products' => [], 'customers' => []], 'stock_exceptions' => []];
            $this->log($companyId, $deviceId, $userId, $batch, $batchUuid, 'rejected', $result);

            return $result;
        }
    }

    private function applyInTransaction(Company $company, int $companyId, ?string $deviceId, int $userId, array $batch, string $batchUuid): array
    {
        $result = DB::transaction(function () use ($company, $companyId, $deviceId, $userId, $batch, $batchUuid) {
            $ops = [];
            $assigned = [];
            $derived = ['products' => [], 'customers' => []];
            $stockExceptions = [];
            $touchedProducts = [];
            $failed = false;

            foreach ($batch['ops'] ?? [] as $op) {
                $opUuid = (string) ($op['op_uuid'] ?? $op['uuid'] ?? '');
                $table = (string) ($op['table'] ?? '');
                $config = SyncRegistry::get($table);
                if ($config === null) {
                    $ops[] = ['op_uuid' => $opUuid, 'status' => 'rejected', 'code' => 'unknown_table', 'table' => $table];
                    $failed = true;
                    break;
                }
                $op['_user_id'] = $userId;
                try {
                    $r = match ($config['kind']) {
                        SyncRegistry::KIND_EVENT => $this->applyEvent($company, $deviceId, $userId, $table, $config, $op, $assigned, $touchedProducts, $stockExceptions),
                        SyncRegistry::KIND_MASTER => $this->master->apply($companyId, $deviceId, $table, $config, $op, $this->serializer),
                        SyncRegistry::KIND_POULTRY => $this->applyPoultry($companyId, $config, $op),
                        default => ['status' => 'rejected', 'code' => 'read_only_table'],
                    };
                } catch (BusinessRuleException $e) {
                    $r = ['status' => 'rejected', 'code' => $e->errorCode() === 'business_rule' ? 'validation' : $e->errorCode(), 'message' => $e->getMessage(), 'errors' => $e->toErrors()];
                }
                $entry = ['op_uuid' => $opUuid, 'table' => $table, 'uuid' => $op['uuid'] ?? null, 'status' => $r['status']];
                foreach (['code', 'message', 'errors', 'server_data', 'conflict_id', 'parent'] as $k) {
                    if (isset($r[$k])) {
                        $entry[$k] = $r[$k];
                    }
                }
                if (isset($r['model'])) {
                    $entry['server_seq'] = (int) $r['model']->server_seq;
                    $entry['id'] = $r['model']->getKey();
                    $entry['version'] = (int) $r['model']->version;
                }
                $ops[] = $entry;
                if ($r['status'] === 'rejected') {
                    $failed = true;
                    break;
                }
            }

            if ($failed) {
                // One bad op rolls the whole batch back (A.2); the verdict travels out in the exception.
                throw new BatchRejected($ops);
            }

            $status = collect($ops)->contains(fn ($o) => $o['status'] === 'conflict') ? 'conflict' : 'applied';
            foreach (array_unique($touchedProducts) as $productId) {
                $product = StockItem::withoutGlobalScopes()->where('id', $productId)->first();
                if ($product) {
                    $derived['products'][$product->uuid] = ['current_quantity' => (string) $product->current_quantity, 'server_seq' => (int) $product->server_seq, 'version' => (int) $product->version];
                }
            }

            $result = [
                'batch_uuid' => $batchUuid, 'status' => $status, 'ops' => $ops,
                'server_seq_max' => SyncSequence::current(), 'assigned' => $assigned, 'derived' => $derived, 'stock_exceptions' => $stockExceptions,
            ];
            $this->log($companyId, $deviceId, $userId, $batch, $batchUuid, $status, $result);

            return $result;
        });

        return $result;
    }

    private function log(int $companyId, ?string $deviceId, int $userId, array $batch, string $batchUuid, string $status, array $result): void
    {
        SyncBatch::updateOrCreate(
            ['company_id' => $companyId, 'batch_uuid' => $batchUuid],
            ['device_id' => $deviceId, 'user_id' => $userId, 'kind' => $batch['kind'] ?? 'generic', 'status' => $status, 'ops_count' => count($batch['ops'] ?? []), 'payload' => null, 'result' => $result, 'applied_at' => now()]
        );
    }

    /** Batches held while the subscription was lapsed are applied on renewal. */
    public function applyHeld(Company $company): int
    {
        $held = SyncBatch::where('company_id', $company->id)->where('status', 'held')->orderBy('id')->get();
        $count = 0;
        foreach ($held as $batch) {
            $device = $batch->device_id ? Device::withoutGlobalScopes()->where('company_id', $company->id)->where('device_id', $batch->device_id)->first() : null;
            $this->applyBatch($company->fresh(), $device, (int) ($batch->user_id ?? $company->owner_id), $batch->payload ?? [], false);
            $count++;
        }

        return $count;
    }

    // ------------------------------------------------------------------

    private function applyEvent(Company $company, ?string $deviceId, int $userId, string $table, array $config, array $op, array &$assigned, array &$touchedProducts, array &$stockExceptions): array
    {
        $companyId = (int) $company->id;
        $uuid = (string) $op['uuid'];
        $action = $op['action'] ?? 'insert';
        $data = is_array($op['data'] ?? null) ? $op['data'] : [];

        if (! empty($config['derived'])) {
            // Sale lines are created by the server from sale.items — accept the op once its parent exists.
            $parentUuid = $data['sale_uuid'] ?? null;
            $sale = $parentUuid ? SaleRecord::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $parentUuid)->first() : null;
            if ($sale === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'sale_uuid'];
            }

            return ['status' => 'replayed'];
        }

        return match ($config['handler'] ?? '') {
            'sale' => $this->applySale($company, $deviceId, $userId, $uuid, $action, $data, $op, $assigned, $touchedProducts, $stockExceptions),
            'payment' => $this->applyPayment($companyId, $userId, $uuid, $action, $data),
            'movement' => $this->applyMovement($company, $deviceId, $userId, $uuid, $action, $data, $touchedProducts, $stockExceptions),
            'shift' => $this->applyShift($companyId, $deviceId, $userId, $uuid, $action, $data),
            'return' => $this->applyReturn($companyId, $userId, $uuid, $data, $touchedProducts),
            'grn' => $this->applyGoodsReceipt($companyId, $deviceId, $userId, $uuid, $data, $touchedProducts),
            'stock_take' => $this->applyStockTake($companyId, $deviceId, $userId, $uuid, $action, $data, $touchedProducts),
            default => ['status' => 'rejected', 'code' => 'unknown_table'],
        };
    }

    private function applySale(Company $company, ?string $deviceId, int $userId, string $uuid, string $action, array $data, array $op, array &$assigned, array &$touchedProducts, array &$stockExceptions): array
    {
        $companyId = (int) $company->id;
        $existing = SaleRecord::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first();

        if ($action === 'void') {
            if ($existing === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'uuid'];
            }
            $voided = $this->sales->void($existing, $data['reason'] ?? 'Voided on device', $userId);
            foreach ($voided->saleRecordItems as $line) {
                $touchedProducts[] = (int) $line->stock_item_id;
            }

            return ['status' => 'applied', 'model' => $voided];
        }
        if ($action !== 'insert' && $action !== 'upsert') {
            return ['status' => 'rejected', 'code' => 'immutable_event', 'message' => 'Sales are append-only; void or refund instead.'];
        }
        if ($existing !== null) {
            $assigned['sales'][$uuid] = ['receipt_number' => $existing->receipt_number, 'id' => $existing->id, 'server_seq' => (int) $existing->server_seq];

            return ['status' => 'replayed', 'model' => $existing];
        }

        $items = [];
        foreach ($data['items'] ?? [] as $line) {
            $productUuid = $line['product_uuid'] ?? null;
            $productId = $productUuid ? StockItem::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $productUuid)->value('id') : null;
            if ($productId === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'product_uuid'];
            }
            $unitId = null;
            if (! empty($line['unit_uuid'])) {
                $unitId = \App\Models\Unit::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $line['unit_uuid'])->value('id');
                if ($unitId === null) {
                    return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'unit_uuid'];
                }
            }
            $items[] = ['stock_item_id' => (int) $productId, 'quantity' => $line['quantity'] ?? 0, 'unit_price' => $line['unit_price'] ?? null, 'discount_amount' => $line['discount_amount'] ?? 0, 'unit_id' => $unitId];
        }
        $customerId = null;
        if (! empty($data['customer_uuid'])) {
            $customerId = \App\Models\Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $data['customer_uuid'])->value('id');
            if ($customerId === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'customer_uuid'];
            }
        }
        $shiftId = null;
        if (! empty($data['shift_uuid'])) {
            $shiftId = \App\Models\Shift::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $data['shift_uuid'])->value('id');
            if ($shiftId === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'shift_uuid'];
            }
        }
        if ($items === []) {
            return ['status' => 'rejected', 'code' => 'validation', 'message' => 'A sale needs at least one item.'];
        }

        $occurredAt = isset($data['occurred_at']) ? \Illuminate\Support\Carbon::createFromTimestampMs((int) $data['occurred_at']) : now();
        $policy = $company->negative_stock_policy ?? 'flag';
        $before = StockItem::withoutGlobalScopes()->whereIn('id', array_column($items, 'stock_item_id'))->pluck('current_quantity', 'id');

        $result = $this->sales->checkout($companyId, $userId, [
            'client_uuid' => $uuid,
            'items' => $items,
            'payments' => [], // payments are their own ops (A.2); legacy clients send amount_paid below
            'amount_paid' => 0,
            'discount_amount' => $data['discount_amount'] ?? 0,
            'discount_reason' => $data['discount_reason'] ?? null,
            'customer_name' => $data['customer_name'] ?? 'Walk-in Customer',
            'customer_phone' => $data['customer_phone'] ?? null,
            'payment_method' => $data['payment_method'] ?? 'cash',
            'sale_date' => $occurredAt,
            'notes' => $data['notes'] ?? null,
            'provisional_number' => $data['provisional_number'] ?? null,
            'device_id' => $deviceId,
            'customer_id' => $customerId,
            'shift_id' => $shiftId,
            'from_sync' => true, // offline sales are never rejected for credit limits or closed shifts
            'allow_negative_stock' => true, // a completed offline sale is never rejected for stock (Appendix E)
        ]);
        $sale = $result['sale'];

        // Older clients: no payment ops, just amount_paid on the sale.
        $legacyPaid = round((float) ($data['amount_paid'] ?? 0), 2);
        if ($legacyPaid > 0 && empty($data['has_payment_ops'])) {
            $this->payments->record($sale, ['client_uuid' => $uuid.'-pay', 'amount' => min($legacyPaid, (float) $sale->total_amount), 'method' => $data['payment_method'] ?? 'cash', 'received_by_id' => $userId, 'received_at' => $occurredAt]);
            $sale = $sale->fresh();
        }

        foreach ($items as $line) {
            $touchedProducts[] = $line['stock_item_id'];
            $product = StockItem::withoutGlobalScopes()->find($line['stock_item_id']);
            if ($product && (float) $product->current_quantity < 0 && ! $product->allow_negative_stock && $policy !== 'allow') {
                $available = (float) ($before[$product->id] ?? 0);
                $conflict = $this->master->conflict($companyId, $deviceId, 'sales', $uuid, 'stock_exception',
                    ['product_uuid' => $product->uuid, 'product' => $product->name, 'sold' => (float) $line['quantity'], 'available_before' => $available, 'sale_uuid' => $uuid],
                    ['current_quantity' => (string) $product->current_quantity],
                    'Sold '.rtrim(rtrim(number_format((float) $line['quantity'], 3, '.', ''), '0'), '.').' of '.$product->name.' but only '.rtrim(rtrim(number_format(max($available, 0), 3, '.', ''), '0'), '.').' was in stock');
                $stockExceptions[] = ['sale_uuid' => $uuid, 'product_uuid' => $product->uuid, 'current_quantity' => (string) $product->current_quantity, 'conflict_id' => $conflict->id];
                if (! $sale->stock_exception) {
                    $sale->stock_exception = true;
                    $sale->saveQuietlySynced();
                }
            }
        }

        $assigned['sales'][$uuid] = ['receipt_number' => $sale->receipt_number, 'invoice_number' => $sale->invoice_number, 'id' => $sale->id, 'server_seq' => (int) $sale->server_seq, 'total_amount' => (string) $sale->total_amount];

        return ['status' => 'applied', 'model' => $sale];
    }

    private function applyPayment(int $companyId, int $userId, string $uuid, string $action, array $data): array
    {
        if ($action !== 'insert' && $action !== 'upsert') {
            return ['status' => 'rejected', 'code' => 'immutable_event', 'message' => 'Payments are append-only; reverse instead.'];
        }
        $existing = Payment::withoutGlobalScopes()->where('company_id', $companyId)->where(fn ($q) => $q->where('uuid', $uuid)->orWhere('client_uuid', $uuid))->first();
        if ($existing) {
            return ['status' => 'replayed', 'model' => $existing];
        }
        $saleUuid = $data['sale_uuid'] ?? null;
        if (empty($saleUuid) && ! empty($data['customer_uuid'])) {
            // Money received on a customer's account: allocated to their open sales oldest-first.
            $customer = \App\Models\Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $data['customer_uuid'])->first();
            if ($customer === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'customer_uuid'];
            }
            $created = (new \App\Services\Shop\CustomerService())->receivePayment($customer, (float) ($data['amount'] ?? 0), $data['method'] ?? 'cash', $userId, $data['reference'] ?? null, $uuid);

            return ['status' => 'applied', 'model' => $created[0]];
        }
        $sale = $saleUuid ? SaleRecord::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $saleUuid)->first() : null;
        if ($sale === null) {
            return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'sale_uuid'];
        }
        $payment = $this->sales->addPayment($sale, [
            'client_uuid' => $uuid, 'amount' => $data['amount'] ?? 0, 'method' => $data['method'] ?? null, 'reference' => $data['reference'] ?? null,
            'provider' => $data['provider'] ?? null, 'notes' => $data['notes'] ?? null,
            'received_at' => isset($data['received_at']) ? \Illuminate\Support\Carbon::createFromTimestampMs((int) $data['received_at']) : now(),
        ], $userId);

        return ['status' => 'applied', 'model' => $payment];
    }

    private function applyMovement(Company $company, ?string $deviceId, int $userId, string $uuid, string $action, array $data, array &$touchedProducts, array &$stockExceptions): array
    {
        $companyId = (int) $company->id;
        if ($action !== 'insert' && $action !== 'upsert') {
            return ['status' => 'rejected', 'code' => 'immutable_event', 'message' => 'Stock movements are append-only; reverse instead.'];
        }
        $existing = StockRecord::withoutGlobalScopes()->where('company_id', $companyId)->where(fn ($q) => $q->where('uuid', $uuid)->orWhere('client_uuid', $uuid))->first();
        if ($existing) {
            return ['status' => 'replayed', 'model' => $existing];
        }
        // Sale movements are generated by the server from the sale op itself.
        if (($data['reference_type'] ?? null) === 'sale' && ! empty($data['reference_uuid'] ?? $data['sale_uuid'] ?? null)) {
            $saleUuid = $data['reference_uuid'] ?? $data['sale_uuid'];
            $sale = SaleRecord::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $saleUuid)->first();

            return $sale ? ['status' => 'replayed'] : ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'reference_uuid'];
        }
        $productUuid = $data['product_uuid'] ?? null;
        $product = $productUuid ? StockItem::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $productUuid)->first() : null;
        if ($product === null) {
            return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'product_uuid'];
        }
        $type = self::movementType((string) ($data['type'] ?? ''), (float) ($data['quantity'] ?? 0));
        $qty = abs((float) ($data['quantity'] ?? 0));
        $record = $this->stock->record([
            'client_uuid' => $uuid, 'stock_item_id' => (int) $product->id, 'type' => $type, 'quantity' => $qty,
            'description' => $data['description'] ?? $data['reason'] ?? null,
            'date' => isset($data['occurred_at']) ? \Illuminate\Support\Carbon::createFromTimestampMs((int) $data['occurred_at']) : now(),
            'unit_cost' => $data['unit_cost'] ?? null, 'selling_price' => $data['unit_price'] ?? null,
            'reason' => $data['reason'] ?? null, 'image' => $data['image'] ?? null,
            'created_by_id' => $userId, 'allow_negative' => true,
        ]);
        $touchedProducts[] = (int) $product->id;
        $product->refresh();
        if ((float) $product->current_quantity < 0 && ! $product->allow_negative_stock && ($company->negative_stock_policy ?? 'flag') !== 'allow') {
            $conflict = $this->master->conflict($companyId, $deviceId, 'stock_movements', $uuid, 'stock_exception',
                ['product_uuid' => $product->uuid, 'product' => $product->name, 'quantity' => $qty, 'type' => $type],
                ['current_quantity' => (string) $product->current_quantity]);
            $stockExceptions[] = ['movement_uuid' => $uuid, 'product_uuid' => $product->uuid, 'current_quantity' => (string) $product->current_quantity, 'conflict_id' => $conflict->id];
        }

        return ['status' => 'applied', 'model' => $record];
    }

    private function applyShift(int $companyId, ?string $deviceId, int $userId, string $uuid, string $action, array $data): array
    {
        $svc = new \App\Services\Shop\ShiftService();
        $shift = \App\Models\Shift::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first();
        if ($action === 'insert' || $action === 'upsert') {
            if ($shift) {
                return ['status' => 'replayed', 'model' => $shift];
            }
            // An older open shift of the same cashier on this device is closed first (it was closed offline and its close op is behind).
            $shift = $svc->open($companyId, $userId, (float) ($data['opening_float'] ?? 0), $deviceId, $uuid, $data['notes'] ?? null);
            if (isset($data['opened_at'])) {
                $shift->opened_at = \Illuminate\Support\Carbon::createFromTimestampMs((int) $data['opened_at']);
                $shift->saveQuietlySynced();
            }

            return ['status' => 'applied', 'model' => $shift];
        }
        if ($action === 'update' && ($data['status'] ?? '') === 'closed') {
            if ($shift === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'uuid'];
            }
            $shift = $svc->close($shift, (float) ($data['counted_cash'] ?? 0), $userId, $data['notes'] ?? null);

            return ['status' => 'applied', 'model' => $shift];
        }

        return ['status' => 'rejected', 'code' => 'immutable_event', 'message' => 'Shifts change only by opening and closing.'];
    }

    private function applyReturn(int $companyId, int $userId, string $uuid, array $data, array &$touchedProducts): array
    {
        $existing = \App\Models\SaleReturn::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first();
        if ($existing) {
            return ['status' => 'replayed', 'model' => $existing];
        }
        $sale = SaleRecord::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $data['sale_uuid'] ?? '')->first();
        if ($sale === null) {
            return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'sale_uuid'];
        }
        $lines = [];
        foreach ($data['items'] ?? [] as $l) {
            $productId = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $l['product_uuid'] ?? '')->value('id');
            $item = \App\Models\SaleRecordItem::withoutGlobalScopes()->where('sale_record_id', $sale->id)
                ->when(! empty($l['sale_item_uuid']), fn ($q) => $q->where('uuid', $l['sale_item_uuid']), fn ($q) => $q->where('stock_item_id', $productId))
                ->first();
            if ($item === null) {
                return ['status' => 'rejected', 'code' => 'validation', 'message' => 'A returned item is not part of the sale.'];
            }
            $lines[] = ['sale_item_id' => $item->id, 'quantity' => $l['quantity'] ?? 0, 'restock' => (bool) ($l['restock'] ?? true)];
            $touchedProducts[] = (int) $item->stock_item_id;
        }
        $shiftId = ! empty($data['shift_uuid']) ? \App\Models\Shift::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $data['shift_uuid'])->value('id') : null;
        $return = (new \App\Services\Shop\ReturnService())->create($sale, $lines, $userId, $data['reason'] ?? null, $data['refund_method'] ?? 'cash', $uuid, $shiftId);

        return ['status' => 'applied', 'model' => $return];
    }

    private function applyGoodsReceipt(int $companyId, ?string $deviceId, int $userId, string $uuid, array $data, array &$touchedProducts): array
    {
        $existing = \App\Models\GoodsReceipt::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first();
        if ($existing) {
            return ['status' => 'replayed', 'model' => $existing];
        }
        $supplierId = null;
        if (! empty($data['supplier_uuid'])) {
            $supplierId = \App\Models\Supplier::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $data['supplier_uuid'])->value('id');
            if ($supplierId === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'supplier_uuid'];
            }
        }
        $lines = [];
        foreach ($data['items'] ?? [] as $l) {
            $productId = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $l['product_uuid'] ?? '')->value('id');
            if ($productId === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'product_uuid'];
            }
            $lines[] = ['stock_item_id' => (int) $productId, 'quantity' => $l['quantity'] ?? 0, 'unit_cost' => $l['unit_cost'] ?? 0];
            $touchedProducts[] = (int) $productId;
        }
        $grn = (new \App\Services\Shop\GoodsReceiptService())->receive($companyId, $userId, $lines, $supplierId, $data['invoice_ref'] ?? null, (float) ($data['amount_paid'] ?? 0),
            $data['payment_method'] ?? 'cash', isset($data['received_on']) ? (string) $data['received_on'] : null, $uuid, $data['notes'] ?? null, $deviceId);

        return ['status' => 'applied', 'model' => $grn];
    }

    private function applyStockTake(int $companyId, ?string $deviceId, int $userId, string $uuid, string $action, array $data, array &$touchedProducts): array
    {
        $svc = new \App\Services\Shop\StockTakeService();
        $take = \App\Models\StockTake::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first();
        if ($take === null) {
            $categoryId = ! empty($data['category_uuid']) ? StockCategory::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $data['category_uuid'])->value('id') : null;
            $take = $svc->create($companyId, $userId, (string) ($data['name'] ?? ''), $categoryId, $uuid, $deviceId);
        }
        $counts = [];
        foreach ($data['counts'] ?? [] as $c) {
            $productId = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $c['product_uuid'] ?? '')->value('id');
            if ($productId === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => 'product_uuid'];
            }
            $counts[] = ['stock_item_id' => (int) $productId, 'counted_quantity' => $c['counted_quantity'] ?? 0];
            $touchedProducts[] = (int) $productId;
        }
        if ($counts !== [] && $take->status === 'draft') {
            $take = $svc->count($take, $counts);
        }
        if (($data['status'] ?? '') === 'posted' && $take->status === 'draft') {
            $take = $svc->post($take, $userId);
        }

        return ['status' => 'applied', 'model' => $take];
    }

    /** Wire movement types (lower_snake) → StockService types; a signed quantity decides direction for adjustments. */
    public static function movementType(string $wire, float $signedQty): string
    {
        $map = [
            'sale' => 'Sale', 'purchase' => 'Purchase', 'purchase_receipt' => 'Purchase', 'stock_in' => 'Stock In', 'return' => 'Return',
            'adjustment_in' => 'Adjustment In', 'adjustment_out' => 'Adjustment Out', 'damage' => 'Damage', 'expired' => 'Expired',
            'lost' => 'Lost', 'internal_use' => 'Internal Use', 'opening' => 'Opening', 'stock_take' => $signedQty >= 0 ? 'Adjustment In' : 'Adjustment Out',
            'adjustment' => $signedQty >= 0 ? 'Adjustment In' : 'Adjustment Out', 'transfer_in' => 'Transfer In', 'transfer_out' => 'Transfer Out', 'other' => 'Other',
        ];
        $key = strtolower(str_replace([' ', '-'], '_', $wire));
        if (isset($map[$key])) {
            return $map[$key];
        }
        if (in_array($wire, StockService::types(), true)) {
            return $wire;
        }
        throw BusinessRuleException::make('invalid_movement_type', 'Unknown stock movement type "'.$wire.'".', ['allowed' => array_keys($map)]);
    }

    private function applyPoultry(int $companyId, array $config, array $op): array
    {
        $model = $config['model'];
        $payload = is_array($op['data'] ?? null) ? $op['data'] : [];
        $payload['updated_at'] = $payload['updated_at'] ?? ($op['client_updated_at'] ?? 0);
        $payload['created_at'] = $payload['created_at'] ?? ($op['client_created_at'] ?? $payload['updated_at']);
        $r = $model::syncPush($companyId, (string) $op['uuid'], $payload, ($op['action'] ?? '') === 'delete', $payload['entered_by'] ?? null);
        $row = $model::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $op['uuid'])->first();
        if ($r['conflict']) {
            return ['status' => 'conflict', 'code' => 'stale_version', 'server_data' => $r['server_data'], 'model' => $row];
        }

        return ['status' => 'applied', 'model' => $row];
    }
}

<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Services\Billing\Quotas;
use Illuminate\Support\Facades\DB;

/**
 * Locations and transfers (plan P4-4, decision H6: a Business-plan feature).
 * A transfer is a Transfer Out at one location and a Transfer In at the other
 * (the product total never changes); batches travel with their expiry dates.
 */
class TransferService
{
    public function createLocation(Company $company, string $name, ?string $address = null): int
    {
        $existing = DB::table('locations')->where('company_id', $company->id)->count();
        if ($existing >= 1 && ! (new Quotas())->featureOn($company, 'multi_location')) {
            throw BusinessRuleException::make('feature_not_in_plan', 'More than one location is part of the Business plan. Upgrade under Plan & billing.', ['feature' => 'multi_location']);
        }
        $max = (new Quotas())->limits($company)['max_locations'] ?? null;
        if ($max !== null && $existing >= (int) $max && (new Quotas())->featureOn($company, 'multi_location') && (int) $max > 1) {
            throw BusinessRuleException::make('plan_limit_reached', "Your plan allows {$max} locations.", ['limit' => 'max_locations', 'max' => (int) $max, 'used' => $existing]);
        }
        if (DB::table('locations')->where('company_id', $company->id)->where('name', trim($name))->exists()) {
            throw BusinessRuleException::make('duplicate_location', 'There is already a location with that name.');
        }
        LocationStock::defaultLocation((int) $company->id);

        return (int) DB::table('locations')->insertGetId(['company_id' => $company->id, 'name' => trim($name), 'address' => $address, 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Rename, re-address, close or reopen a location. The main location cannot be closed, and a
     * location still holding stock must be emptied (moved or counted to zero) first.
     *
     * @param  array{name?: string, address?: string|null, is_active?: bool|int|null}  $data
     */
    public function updateLocation(int $companyId, int $locationId, array $data): object
    {
        /** @var object{id: int, is_default: int|bool}|null $loc */
        $loc = DB::table('locations')->where('company_id', $companyId)->where('id', $locationId)->first();
        if ($loc === null) {
            throw BusinessRuleException::make('location_not_found', 'Location not found.');
        }
        $data = array_intersect_key($data, array_flip(['name', 'address', 'is_active']));
        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
            if (DB::table('locations')->where('company_id', $companyId)->where('name', $data['name'])->where('id', '!=', $locationId)->exists()) {
                throw BusinessRuleException::make('duplicate_location', 'There is already a location with that name.');
            }
        }
        if (array_key_exists('is_active', $data) && $data['is_active'] !== null && ! $data['is_active']) {
            if ($loc->is_default) {
                throw BusinessRuleException::make('default_location', 'The main location cannot be closed.');
            }
            if (DB::table('stock_levels')->where('location_id', $locationId)->where('quantity', '!=', 0)->exists()) {
                throw BusinessRuleException::make('location_has_stock', 'Move or count the stock at this location to zero before closing it.');
            }
        }
        if (array_key_exists('is_active', $data)) {
            if ($data['is_active'] === null) {
                unset($data['is_active']);
            } else {
                $data['is_active'] = (bool) $data['is_active'];
            }
        }
        if ($data !== []) {
            DB::table('locations')->where('id', $locationId)->update($data + ['updated_at' => now()]);
        }

        return DB::table('locations')->where('id', $locationId)->first();
    }

    /**
     * Which location a phone sells from (classic Locations "which phone sells where"). Null = the
     * main location. The phone picks it up on its next sync.
     */
    public function assignDevice(int $companyId, int $deviceId, ?int $locationId): void
    {
        $device = DB::table('devices')->where('company_id', $companyId)->where('id', $deviceId)->first(['id', 'revoked_at']);
        if ($device === null) {
            throw BusinessRuleException::make('device_not_found', 'That phone is not linked to this shop.');
        }
        if ($device->revoked_at !== null) {
            throw BusinessRuleException::make('device_revoked', 'That phone was removed from the shop.');
        }
        if ($locationId !== null) {
            $loc = DB::table('locations')->where('company_id', $companyId)->where('id', $locationId)->first(['id', 'is_active']);
            if ($loc === null) {
                throw BusinessRuleException::make('location_not_found', 'Location not found.');
            }
            if (! $loc->is_active) {
                throw BusinessRuleException::make('location_closed', 'That location is closed. Reopen it first.');
            }
        }
        DB::table('devices')->where('id', $deviceId)->update(['location_id' => $locationId, 'updated_at' => now()]);
    }

    /**
     * @param  array<int, array{stock_item_id: int, quantity: float|string}>  $lines
     */
    public function transfer(int $companyId, int $userId, int $fromId, int $toId, array $lines, ?string $notes = null): int
    {
        if ($fromId === $toId) {
            throw BusinessRuleException::make('same_location', 'Choose two different locations.');
        }
        LocationStock::assertLocation($companyId, $fromId);
        LocationStock::assertLocation($companyId, $toId);
        if ($lines === []) {
            throw BusinessRuleException::make('empty_transfer', 'Add at least one product.');
        }

        return DB::transaction(function () use ($companyId, $userId, $fromId, $toId, $lines, $notes) {
            $number = NumberSequencer::next($companyId, 'transfer');
            $id = (int) DB::table('stock_transfers')->insertGetId(['company_id' => $companyId, 'number' => $number, 'from_location_id' => $fromId, 'to_location_id' => $toId,
                'notes' => $notes, 'created_by_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $names = DB::table('locations')->whereIn('id', [$fromId, $toId])->pluck('name', 'id');
            $stock = new StockService();
            foreach ($lines as $l) {
                $qty = round((float) $l['quantity'], 3);
                $item = StockService::lock((int) $l['stock_item_id']);
                if ((int) $item->company_id !== $companyId || $qty <= 0) {
                    throw BusinessRuleException::make('invalid_line', 'Choose a product and a quantity greater than zero.');
                }
                $have = LocationStock::level($fromId, (int) $item->id);
                if ($have < $qty && ! $item->allow_negative_stock) {
                    throw BusinessRuleException::make('insufficient_stock', "{$names[$fromId]} has only ".rtrim(rtrim(number_format($have, 3, '.', ''), '0'), '.')." {$item->name}.",
                        ['stock_item_id' => $item->id, 'available' => $have, 'requested' => $qty]);
                }
                $out = $stock->record(['stock_item_id' => $item->id, 'type' => 'Transfer Out', 'quantity' => $qty, 'location_id' => $fromId, 'created_by_id' => $userId,
                    'description' => "{$number} to {$names[$toId]}", 'reference_type' => 'stock_transfer', 'reference_id' => $id, 'allow_negative' => true]);
                $stock->record(['stock_item_id' => $item->id, 'type' => 'Transfer In', 'quantity' => $qty, 'location_id' => $toId, 'created_by_id' => $userId,
                    'description' => "{$number} from {$names[$fromId]}", 'reference_type' => 'stock_transfer', 'reference_id' => $id, 'batch_in' => LocationStock::taken($out->id)]);
                DB::table('stock_transfer_items')->insert(['company_id' => $companyId, 'stock_transfer_id' => $id, 'stock_item_id' => $item->id, 'quantity' => $qty, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $id;
        });
    }

    /**
     * Send stock now, to arrive later (G2, "in transit"): the Transfer Out is recorded at the sending location
     * at once; the Transfer In only when the other location receives it (receive()). Until then the quantity
     * is on neither shelf: it shows as in transit (inTransit()) on both sides. Same checks as transfer().
     *
     * @param  array<int, array{stock_item_id: int, quantity: float|string}>  $lines
     */
    public function send(int $companyId, int $userId, int $fromId, int $toId, array $lines, ?string $notes = null, ?int $requestId = null): int
    {
        if ($fromId === $toId) {
            throw BusinessRuleException::make('same_location', 'Choose two different locations.');
        }
        LocationStock::assertLocation($companyId, $fromId);
        LocationStock::assertLocation($companyId, $toId);
        if ($lines === []) {
            throw BusinessRuleException::make('empty_transfer', 'Add at least one product.');
        }

        return DB::transaction(function () use ($companyId, $userId, $fromId, $toId, $lines, $notes, $requestId) {
            $number = NumberSequencer::next($companyId, 'transfer');
            $id = (int) DB::table('stock_transfers')->insertGetId(['company_id' => $companyId, 'number' => $number, 'from_location_id' => $fromId, 'to_location_id' => $toId,
                'notes' => $notes, 'created_by_id' => $userId, 'status' => 'in_transit', 'sent_at' => now(), 'stock_request_id' => $requestId, 'created_at' => now(), 'updated_at' => now()]);
            $names = DB::table('locations')->whereIn('id', [$fromId, $toId])->pluck('name', 'id');
            $stock = new StockService();
            foreach ($lines as $l) {
                $qty = round((float) $l['quantity'], 3);
                $item = StockService::lock((int) $l['stock_item_id']);
                if ((int) $item->company_id !== $companyId || $qty <= 0) {
                    throw BusinessRuleException::make('invalid_line', 'Choose a product and a quantity greater than zero.');
                }
                $have = LocationStock::level($fromId, (int) $item->id);
                if ($have < $qty && ! $item->allow_negative_stock) {
                    throw BusinessRuleException::make('insufficient_stock', "{$names[$fromId]} has only ".rtrim(rtrim(number_format($have, 3, '.', ''), '0'), '.')." {$item->name}.",
                        ['stock_item_id' => $item->id, 'available' => $have, 'requested' => $qty]);
                }
                $stock->record(['stock_item_id' => $item->id, 'type' => 'Transfer Out', 'quantity' => $qty, 'location_id' => $fromId, 'created_by_id' => $userId,
                    'description' => "{$number} to {$names[$toId]} (in transit)", 'reference_type' => 'stock_transfer', 'reference_id' => $id, 'allow_negative' => true]);
                DB::table('stock_transfer_items')->insert(['company_id' => $companyId, 'stock_transfer_id' => $id, 'stock_item_id' => $item->id, 'quantity' => $qty, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $id;
        });
    }

    /**
     * Receive a transfer sent with send(): a Transfer In at the receiving location for what arrived (all of it
     * when $received is null), with the batches that left. What did not arrive stays off both shelves
     * (it left the sender's stock when sent) and is shown as short on the transfer.
     *
     * @param  array<int, float|string>|null  $received  stock_item_id => quantity that arrived
     */
    public function receive(int $companyId, int $userId, int $transferId, ?array $received = null): void
    {
        DB::transaction(function () use ($companyId, $userId, $transferId, $received) {
            $t = DB::table('stock_transfers')->where('company_id', $companyId)->where('id', $transferId)->lockForUpdate()->first();
            if ($t === null) {
                throw BusinessRuleException::make('transfer_not_found', 'Transfer not found.');
            }
            if ($t->status !== 'in_transit') {
                throw BusinessRuleException::make('transfer_not_in_transit', "{$t->number} is not on its way: it was already received.");
            }
            $names = DB::table('locations')->whereIn('id', [$t->from_location_id, $t->to_location_id])->pluck('name', 'id');
            $stock = new StockService();
            $items = DB::table('stock_transfer_items')->where('stock_transfer_id', $t->id)->get();
            if ($received !== null) {
                $sent = $items->groupBy('stock_item_id')->map(fn ($g) => (float) $g->sum('quantity'));
                foreach ($received as $pid => $q) {
                    if (! isset($sent[(int) $pid])) {
                        throw BusinessRuleException::make('not_on_transfer', 'That product is not on this transfer.', ['stock_item_id' => (int) $pid]);
                    }
                    if (! is_numeric($q) || (float) $q < 0 || (float) $q > $sent[(int) $pid] + 0.0005) {
                        $name = (string) DB::table('stock_items')->where('id', (int) $pid)->value('name');
                        throw BusinessRuleException::make('invalid_received', "{$name}: receive between 0 and ".rtrim(rtrim(number_format($sent[(int) $pid], 3, '.', ''), '0'), '.').' (what was sent).', ['stock_item_id' => (int) $pid]);
                    }
                }
            }
            $left = $received === null ? null : array_map(fn ($q) => round((float) $q, 3), $received);
            foreach ($items as $i) {
                $pid = (int) $i->stock_item_id;
                $qty = $left === null ? (float) $i->quantity : min((float) $i->quantity, (float) ($left[$pid] ?? 0));
                if ($left !== null) {
                    $left[$pid] = round((float) ($left[$pid] ?? 0) - $qty, 3);
                }
                $qty = round($qty, 3);
                DB::table('stock_transfer_items')->where('id', $i->id)->update(['received_quantity' => $qty, 'updated_at' => now()]);
                if ($qty <= 0) {
                    continue;
                }
                $out = DB::table('stock_records')->where('reference_type', 'stock_transfer')->where('reference_id', $t->id)->where('type', 'Transfer Out')
                    ->where('stock_item_id', $pid)->where('is_reversal', 0)->orderBy('id')->value('id');
                $batches = $out ? LocationStock::taken((int) $out) : [];
                $sentQty = (float) $i->quantity;
                if ($batches !== [] && abs($qty - $sentQty) > 0.0005) { // part arrived: the batches in proportion, the rest on the first
                    $batches = $this->scaleBatches($batches, $qty);
                }
                $stock->record(['stock_item_id' => $pid, 'type' => 'Transfer In', 'quantity' => $qty, 'location_id' => (int) $t->to_location_id, 'created_by_id' => $userId,
                    'description' => "{$t->number} from {$names[$t->from_location_id]}", 'reference_type' => 'stock_transfer', 'reference_id' => $t->id, 'batch_in' => $batches]);
            }
            DB::table('stock_transfers')->where('id', $t->id)->update(['status' => 'received', 'received_at' => now(), 'received_by_id' => $userId, 'updated_at' => now()]);
        });
    }

    /** Batches scaled down to what arrived, oldest expiry first. */
    private function scaleBatches(array $batches, float $qty): array
    {
        usort($batches, fn ($a, $b) => strcmp((string) ($a['expiry_date'] ?? '9999'), (string) ($b['expiry_date'] ?? '9999')));
        $out = [];
        foreach ($batches as $b) {
            if ($qty <= 0) {
                break;
            }
            $take = min($qty, (float) $b['quantity']);
            $out[] = ['batch_number' => $b['batch_number'], 'expiry_date' => $b['expiry_date'], 'quantity' => round($take, 3)];
            $qty = round($qty - $take, 3);
        }
        if ($qty > 0 && $out !== []) {
            $out[0]['quantity'] = round($out[0]['quantity'] + $qty, 3);
        }

        return $out;
    }

    /**
     * Stock on its way (sent, not received yet), per product: to one location (inbound), from it (outbound),
     * or everywhere. @return array<int, float> stock_item_id => quantity
     */
    public function inTransit(int $companyId, ?int $toLocationId = null, ?int $fromLocationId = null, ?array $productIds = null): array
    {
        if (! self::transitReady()) {
            return [];
        }

        return DB::table('stock_transfer_items as i')->join('stock_transfers as t', 't.id', '=', 'i.stock_transfer_id')
            ->where('t.company_id', $companyId)->where('t.status', 'in_transit')
            ->when($toLocationId, fn ($q) => $q->where('t.to_location_id', $toLocationId))
            ->when($fromLocationId, fn ($q) => $q->where('t.from_location_id', $fromLocationId))
            ->when($productIds !== null, fn ($q) => $q->whereIn('i.stock_item_id', $productIds ?: [0]))
            ->groupBy('i.stock_item_id')->selectRaw('i.stock_item_id, SUM(i.quantity) AS q')->pluck('q', 'i.stock_item_id')
            ->map(fn ($q) => (float) $q)->all();
    }

    private static ?bool $transit = null;

    public static function transitReady(): bool
    {
        return self::$transit ??= \Illuminate\Support\Facades\Schema::hasColumn('stock_transfers', 'status');
    }

    /** On-hand per location for one product (or every product when null). */
    public function levels(int $companyId, ?int $stockItemId = null): array
    {
        return DB::table('stock_levels as l')->join('locations as loc', 'loc.id', '=', 'l.location_id')->join('stock_items as p', 'p.id', '=', 'l.stock_item_id')
            ->where('l.company_id', $companyId)->when($stockItemId, fn ($q) => $q->where('l.stock_item_id', $stockItemId))
            ->orderBy('p.name')->orderBy('loc.name')->get(['l.stock_item_id', 'p.name as product', 'l.location_id', 'loc.name as location', 'l.quantity'])
            ->map(fn ($r) => (array) $r + ['quantity' => (float) $r->quantity])->all();
    }
}

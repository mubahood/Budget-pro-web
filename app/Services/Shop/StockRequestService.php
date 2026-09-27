<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse to stores (SUPERMARKET_PLAN.md G2): a store asks for stock, the warehouse approves and sends it,
 * the store receives it (by scanning or by typing what arrived).
 *
 *   requested → approved → sent → received          (cancelled from requested or approved)
 *
 * Sending is TransferService::send (the Transfer Out at the warehouse now); receiving is TransferService::receive
 * (the Transfer In at the store). In between the stock is "in transit", visible on both sides.
 *
 * $scope: the store a store-level member works at (StoreScope). With it, a member may only ask for stock for
 * their own store, send from their own store and receive at their own store.
 */
class StockRequestService
{
    public const STATUSES = ['requested' => 'Requested', 'approved' => 'Approved', 'sent' => 'On the way', 'received' => 'Received', 'cancelled' => 'Cancelled'];

    public const MAX_LINES = 200;

    /**
     * @param  array<int, array{stock_item_id: mixed, quantity: mixed}>  $lines
     */
    public function request(int $companyId, int $userId, int $fromId, int $toId, array $lines, ?string $notes = null, ?int $scope = null): int
    {
        if ($scope !== null && $toId !== $scope) {
            throw BusinessRuleException::make('other_store', 'You can ask for stock for your own store only.');
        }
        if ($fromId === $toId) {
            throw BusinessRuleException::make('same_location', 'Choose the store that asks and a different place to send from.');
        }
        LocationStock::assertLocation($companyId, $fromId);
        LocationStock::assertLocation($companyId, $toId);
        $clean = $this->lines($companyId, $lines);

        return DB::transaction(function () use ($companyId, $userId, $fromId, $toId, $clean, $notes) {
            $id = (int) DB::table('stock_requests')->insertGetId(['company_id' => $companyId, 'number' => NumberSequencer::next($companyId, 'stock_request'),
                'from_location_id' => $fromId, 'to_location_id' => $toId, 'status' => 'requested', 'notes' => $this->note($notes), 'requested_by' => $userId,
                'created_at' => now(), 'updated_at' => now()]);
            foreach ($clean as $pid => $qty) {
                DB::table('stock_request_items')->insert(['company_id' => $companyId, 'stock_request_id' => $id, 'stock_item_id' => $pid, 'quantity' => $qty, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $id;
        });
    }

    /**
     * The warehouse agrees, optionally with other quantities (stock_item_id => quantity; 0 = not sending it).
     *
     * @param  array<int, mixed>|null  $quantities
     */
    public function approve(int $companyId, int $userId, int $requestId, ?array $quantities = null, ?int $scope = null): void
    {
        DB::transaction(function () use ($companyId, $userId, $requestId, $quantities, $scope) {
            $r = $this->locked($companyId, $requestId);
            $this->assertSide($r, $scope, 'from');
            $this->assertStatus($r, ['requested']);
            $this->setQuantities($r, 'approved_quantity', $quantities);
            DB::table('stock_requests')->where('id', $r->id)->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now(), 'updated_at' => now()]);
        });
    }

    /**
     * The warehouse sends it: a transfer in transit (TransferService::send) for the approved (or given) quantities.
     *
     * @param  array<int, mixed>|null  $quantities
     */
    public function send(int $companyId, int $userId, int $requestId, ?array $quantities = null, ?int $scope = null): int
    {
        return DB::transaction(function () use ($companyId, $userId, $requestId, $quantities, $scope) {
            $r = $this->locked($companyId, $requestId);
            $this->assertSide($r, $scope, 'from');
            $this->assertStatus($r, ['requested', 'approved']);
            if ($r->status === 'requested') {
                DB::table('stock_requests')->where('id', $r->id)->update(['approved_by' => $userId, 'approved_at' => now()]);
            }
            $this->setQuantities($r, 'sent_quantity', $quantities, 'approved_quantity');
            $lines = DB::table('stock_request_items')->where('stock_request_id', $r->id)->where('sent_quantity', '>', 0)->get()
                ->map(fn ($i) => ['stock_item_id' => (int) $i->stock_item_id, 'quantity' => (float) $i->sent_quantity])->all();
            if ($lines === []) {
                throw BusinessRuleException::make('empty_transfer', 'Nothing to send: every quantity is zero. Cancel the request instead.');
            }
            $transfer = (new TransferService())->send($companyId, $userId, (int) $r->from_location_id, (int) $r->to_location_id, $lines, "For {$r->number}".($r->notes ? ": {$r->notes}" : ''), (int) $r->id);
            DB::table('stock_requests')->where('id', $r->id)->update(['status' => 'sent', 'sent_by' => $userId, 'sent_at' => now(), 'stock_transfer_id' => $transfer, 'updated_at' => now()]);

            return $transfer;
        });
    }

    /**
     * The store receives it: what arrived (stock_item_id => quantity; null = everything sent).
     *
     * @param  array<int, mixed>|null  $received
     */
    public function receive(int $companyId, int $userId, int $requestId, ?array $received = null, ?int $scope = null): void
    {
        DB::transaction(function () use ($companyId, $userId, $requestId, $received, $scope) {
            $r = $this->locked($companyId, $requestId);
            $this->assertSide($r, $scope, 'to');
            $this->assertStatus($r, ['sent']);
            (new TransferService())->receive($companyId, $userId, (int) $r->stock_transfer_id, $received === null ? null : array_map(fn ($q) => is_numeric($q) ? (float) $q : $q, $received));
            $got = DB::table('stock_transfer_items')->where('stock_transfer_id', $r->stock_transfer_id)->groupBy('stock_item_id')
                ->selectRaw('stock_item_id, SUM(received_quantity) AS q')->pluck('q', 'stock_item_id');
            foreach (DB::table('stock_request_items')->where('stock_request_id', $r->id)->get(['id', 'stock_item_id']) as $i) {
                DB::table('stock_request_items')->where('id', $i->id)->update(['received_quantity' => round((float) ($got[$i->stock_item_id] ?? 0), 3), 'updated_at' => now()]);
            }
            DB::table('stock_requests')->where('id', $r->id)->update(['status' => 'received', 'received_by' => $userId, 'received_at' => now(), 'updated_at' => now()]);
        });
    }

    public function cancel(int $companyId, int $userId, int $requestId, ?int $scope = null): void
    {
        DB::transaction(function () use ($companyId, $userId, $requestId, $scope) {
            $r = $this->locked($companyId, $requestId);
            if ($scope !== null && (int) $r->to_location_id !== $scope && (int) $r->from_location_id !== $scope) {
                throw BusinessRuleException::make('other_store', 'That request belongs to other stores.');
            }
            if ($r->status === 'sent') {
                throw BusinessRuleException::make('request_sent', "{$r->number} is already on its way. Receive it (what arrived), then send back what is not needed.");
            }
            $this->assertStatus($r, ['requested', 'approved']);
            DB::table('stock_requests')->where('id', $r->id)->update(['status' => 'cancelled', 'cancelled_by' => $userId, 'cancelled_at' => now(), 'updated_at' => now()]);
        });
    }

    /** One request with its lines (sent, received and what the sender has on hand). */
    public function find(int $companyId, int $requestId): ?array
    {
        $r = DB::table('stock_requests as r')->where('r.company_id', $companyId)->where('r.id', $requestId)
            ->join('locations as f', 'f.id', '=', 'r.from_location_id')->join('locations as t', 't.id', '=', 'r.to_location_id')
            ->leftJoin('admin_users as u', 'u.id', '=', 'r.requested_by')->leftJoin('stock_transfers as tr', 'tr.id', '=', 'r.stock_transfer_id')
            ->first(['r.*', 'f.name as from_name', 't.name as to_name', 'u.name as requested_by_name', 'tr.number as transfer_number']);
        if ($r === null) {
            return null;
        }
        $items = DB::table('stock_request_items as i')->join('stock_items as p', 'p.id', '=', 'i.stock_item_id')->where('i.stock_request_id', $r->id)
            ->leftJoin('stock_levels as l', fn ($j) => $j->on('l.stock_item_id', '=', 'i.stock_item_id')->where('l.location_id', '=', $r->from_location_id))
            ->orderBy('i.id')->get(['i.id', 'i.stock_item_id', 'p.name', 'p.barcode', 'p.sku', 'i.quantity', 'i.approved_quantity', 'i.sent_quantity', 'i.received_quantity', 'l.quantity as available'])
            ->map(fn ($i) => (array) $i + ['available' => (float) ($i->available ?? 0)])->all();

        return ['request' => $r, 'items' => $items];
    }

    // ── helpers ──────────────────────────────────────────────

    /** @return array<int, float> stock_item_id => quantity (repeated products added together) */
    private function lines(int $companyId, array $lines): array
    {
        if ($lines === []) {
            throw BusinessRuleException::make('empty_request', 'Add at least one product.');
        }
        if (count($lines) > self::MAX_LINES) {
            throw BusinessRuleException::make('too_many_lines', 'A request can have at most '.self::MAX_LINES.' products.');
        }
        $out = [];
        foreach ($lines as $l) {
            $pid = (int) ($l['stock_item_id'] ?? 0);
            $qty = is_numeric($l['quantity'] ?? null) ? round((float) $l['quantity'], 3) : 0.0;
            if ($pid <= 0 || $qty <= 0 || $qty > 1000000) {
                throw BusinessRuleException::make('invalid_line', 'Choose a product and a quantity greater than zero.');
            }
            $out[$pid] = round(($out[$pid] ?? 0) + $qty, 3);
        }
        $found = DB::table('stock_items')->where('company_id', $companyId)->where('is_deleted', 0)->whereIn('id', array_keys($out))->count();
        if ($found !== count($out)) {
            throw BusinessRuleException::make('product_not_found', 'A product on the request was not found.');
        }

        return $out;
    }

    /** Quantities per line into $column: the ones given, else $fallback column, else what was asked for. */
    private function setQuantities(object $r, string $column, ?array $quantities, ?string $fallback = null): void
    {
        foreach (DB::table('stock_request_items')->where('stock_request_id', $r->id)->get() as $i) {
            $q = $quantities[(int) $i->stock_item_id] ?? null;
            if ($q !== null && $q !== '') {
                if (! is_numeric($q) || (float) $q < 0 || (float) $q > 1000000) {
                    throw BusinessRuleException::make('invalid_quantity', 'Quantities must be zero or more.');
                }
                $q = round((float) $q, 3);
            } else {
                $q = $fallback !== null && $i->{$fallback} !== null ? (float) $i->{$fallback} : (float) $i->quantity;
            }
            DB::table('stock_request_items')->where('id', $i->id)->update([$column => $q, 'updated_at' => now()]);
        }
    }

    private function locked(int $companyId, int $requestId): object
    {
        $r = DB::table('stock_requests')->where('company_id', $companyId)->where('id', $requestId)->lockForUpdate()->first();
        if ($r === null) {
            throw BusinessRuleException::make('request_not_found', 'Stock request not found.');
        }

        return $r;
    }

    private function assertStatus(object $r, array $allowed): void
    {
        if (! in_array($r->status, $allowed, true)) {
            throw BusinessRuleException::make('request_status', "{$r->number} is ".strtolower(self::STATUSES[$r->status] ?? $r->status).'. That step is not possible now.', ['status' => $r->status]);
        }
    }

    private function assertSide(object $r, ?int $scope, string $side): void
    {
        if ($scope === null) {
            return;
        }
        $mine = (int) ($side === 'from' ? $r->from_location_id : $r->to_location_id);
        if ($mine !== $scope) {
            throw BusinessRuleException::make('other_store', $side === 'from' ? 'Only the store that sends can approve or send this request.' : 'Only the store that asked can receive this stock.');
        }
    }

    private function note(?string $notes): ?string
    {
        $n = trim((string) $notes);

        return $n === '' ? null : mb_substr($n, 0, 500);
    }
}

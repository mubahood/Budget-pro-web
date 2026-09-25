<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Services\Messaging\Messenger;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders (plan A5, P4-1): draft → sent → partially_received → received
 * (or cancelled). Receiving creates a goods receipt that moves stock, updates the
 * ordered lines and records any cost difference against the order.
 */
class PurchaseOrderService
{
    /**
     * @param  array<int, array{stock_item_id: int, quantity: float|string, unit_cost?: float|string|null}>  $lines
     */
    public function create(int $companyId, int $userId, array $lines, ?int $supplierId = null, ?string $expectedDate = null, ?string $notes = null): PurchaseOrder
    {
        return DB::transaction(function () use ($companyId, $userId, $lines, $supplierId, $expectedDate, $notes) {
            $po = new PurchaseOrder();
            $po->company_id = $companyId;
            $po->number = NumberSequencer::next($companyId, 'purchase_order');
            $po->status = 'draft';
            $po->order_date = now();
            $po->created_by_id = $userId;
            $this->fill($po, $lines, $supplierId, $expectedDate, $notes);

            return $po->load('items');
        });
    }

    public function update(PurchaseOrder $po, array $lines, ?int $supplierId, ?string $expectedDate, ?string $notes): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw BusinessRuleException::make('po_not_draft', 'Only a draft order can be changed. Cancel it and make a new one.');
        }

        return DB::transaction(function () use ($po, $lines, $supplierId, $expectedDate, $notes) {
            $po->items()->delete();
            $this->fill($po, $lines, $supplierId, $expectedDate, $notes);

            return $po->load('items');
        });
    }

    private function fill(PurchaseOrder $po, array $lines, ?int $supplierId, ?string $expectedDate, ?string $notes): void
    {
        if ($lines === []) {
            throw BusinessRuleException::make('empty_order', 'Add at least one product.');
        }
        if ($supplierId && ! Supplier::withoutGlobalScopes()->where('company_id', $po->company_id)->whereKey($supplierId)->exists()) {
            throw BusinessRuleException::make('supplier_not_found', 'Supplier not found.');
        }
        $po->supplier_id = $supplierId;
        $po->expected_date = $expectedDate ? Carbon::parse($expectedDate) : null;
        $po->notes = $notes;
        $po->save();
        $total = 0.0;
        $seen = [];
        foreach ($lines as $l) {
            $qty = round((float) $l['quantity'], 3);
            $product = StockItem::withoutGlobalScopes()->where('company_id', $po->company_id)->find($l['stock_item_id']);
            if ($product === null) {
                throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
            }
            if ($qty <= 0) {
                throw BusinessRuleException::make('invalid_line', 'Quantities must be greater than zero.');
            }
            if (isset($seen[$product->id])) {
                throw BusinessRuleException::make('duplicate_line', "{$product->name} is on the order twice.");
            }
            $seen[$product->id] = true;
            $cost = isset($l['unit_cost']) && $l['unit_cost'] !== '' ? round((float) $l['unit_cost'], 2) : round((float) $product->buying_price, 2);
            PurchaseOrderItem::create(['company_id' => $po->company_id, 'purchase_order_id' => $po->id, 'stock_item_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost]);
            $total += $qty * $cost;
        }
        $po->subtotal = round($total, 2);
        $po->save();
    }

    /** The order as a message for the supplier (plan A5 "send PO on WhatsApp"). */
    public function text(PurchaseOrder $po): string
    {
        $company = Company::withoutGlobalScopes()->find($po->company_id);
        $lines = [];
        foreach ($po->items()->with('product')->get() as $i) {
            $qty = rtrim(rtrim(number_format((float) $i->quantity, 3, '.', ''), '0'), '.');
            $lines[] = "• {$qty} × {$i->product?->name}".((float) $i->unit_cost > 0 ? ' @ '.number_format((float) $i->unit_cost) : '');
        }

        return "*Purchase order {$po->number}*\nFrom: {$company?->name}".($company?->phone_number ? " ({$company->phone_number})" : '')."\n"
            .($po->expected_date ? 'Needed by: '.$po->expected_date->format('d M Y')."\n" : '')."\n".implode("\n", $lines)
            ."\n\nTotal: ".Money::format($po->subtotal, 0, (int) $po->company_id).($po->notes ? "\nNote: {$po->notes}" : '')."\nPlease confirm. Thank you!";
    }

    /**
     * Mark the order sent. Returns the text and a wa.me link for sending from the owner's WhatsApp;
     * with $viaApi the message also goes out through the WhatsApp/SMS provider.
     *
     * @return array{order: PurchaseOrder, text: string, whatsapp_url: ?string}
     */
    public function send(PurchaseOrder $po, bool $viaApi = false): array
    {
        if (! in_array($po->status, ['draft', 'sent'], true)) {
            throw BusinessRuleException::make('po_closed', 'This order was already received or cancelled.');
        }
        $po->status = 'sent';
        $po->sent_at = now();
        $po->save();
        $text = $this->text($po);
        $supplier = $po->supplier;
        $country = Company::withoutGlobalScopes()->find($po->company_id)?->country ?? 'UG';
        $phone = $supplier?->phone ? Phone::e164($supplier->phone, $country) : null;
        if ($viaApi && $phone) {
            app(Messenger::class)->send($phone, $text, ['whatsapp', 'sms'], ['company_id' => $po->company_id, 'purpose' => 'purchase_order']);
        }

        return ['order' => $po->load('items'), 'text' => $text, 'whatsapp_url' => $phone ? 'https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode($text) : null];
    }

    /**
     * Receive goods against the order (partials allowed). Lines without a quantity are skipped.
     *
     * @param  array<int, array{purchase_order_item_id: int, quantity?: float|string|null, unit_cost?: float|string|null, batch_number?: string|null, expiry_date?: string|null}>  $lines
     */
    public function receive(PurchaseOrder $po, int $userId, array $lines, float $amountPaid = 0, string $paymentMethod = 'cash', ?string $invoiceRef = null, ?string $receivedOn = null, ?string $clientUuid = null): GoodsReceipt
    {
        if (! in_array($po->status, ['draft', 'sent', 'partially_received'], true)) {
            throw BusinessRuleException::make('po_closed', 'This order was already received or cancelled.');
        }

        return DB::transaction(function () use ($po, $userId, $lines, $amountPaid, $paymentMethod, $invoiceRef, $receivedOn, $clientUuid) {
            $items = PurchaseOrderItem::where('purchase_order_id', $po->id)->lockForUpdate()->get()->keyBy('id');
            $grnLines = [];
            foreach ($lines as $l) {
                $item = $items[(int) $l['purchase_order_item_id']] ?? null;
                $qty = round((float) ($l['quantity'] ?? 0), 3);
                if ($item === null) {
                    throw BusinessRuleException::make('po_line_not_found', 'That product is not on this order.');
                }
                if ($qty <= 0) {
                    continue;
                }
                $grnLines[] = ['stock_item_id' => $item->stock_item_id, 'quantity' => $qty, 'unit_cost' => isset($l['unit_cost']) && $l['unit_cost'] !== '' ? $l['unit_cost'] : $item->unit_cost,
                    'purchase_order_item_id' => $item->id, 'expected_unit_cost' => $item->unit_cost, 'batch_number' => $l['batch_number'] ?? null, 'expiry_date' => $l['expiry_date'] ?? null];
                $item->received_quantity = round((float) $item->received_quantity + $qty, 3); // over-delivery is accepted and recorded
                $item->save();
            }
            if ($grnLines === []) {
                throw BusinessRuleException::make('empty_receipt', 'Enter the quantities that arrived.');
            }
            $grn = (new GoodsReceiptService())->receive((int) $po->company_id, $userId, $grnLines, $po->supplier_id, $invoiceRef, $amountPaid, $paymentMethod, $receivedOn, $clientUuid, 'Against '.$po->number, null, $po->id);
            $open = $items->contains(fn (PurchaseOrderItem $i) => $i->outstanding() > 0);
            $po->status = $open ? 'partially_received' : 'received';
            $po->received_at = $open ? null : now();
            $po->save();

            return $grn;
        });
    }

    /** Cancel what is still outstanding; goods already received stay received. */
    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        if (! $po->isOpen()) {
            throw BusinessRuleException::make('po_closed', 'This order is already closed.');
        }
        $po->status = $po->status === 'partially_received' ? 'received' : 'cancelled';
        $po->cancelled_at = now();
        $po->save();

        return $po;
    }

    /** Received vs ordered per line, with cost variances (for the order screen and reports). */
    public function progress(PurchaseOrder $po): array
    {
        $variance = (float) DB::table('goods_receipt_items')->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->where('goods_receipts.purchase_order_id', $po->id)->whereNotNull('expected_unit_cost')
            ->sum(DB::raw('(goods_receipt_items.unit_cost - goods_receipt_items.expected_unit_cost) * goods_receipt_items.quantity'));

        return [
            'lines' => $po->items()->with('product')->get()->map(fn (PurchaseOrderItem $i) => [
                'id' => $i->id, 'stock_item_id' => $i->stock_item_id, 'name' => $i->product?->name, 'ordered' => (float) $i->quantity,
                'received' => (float) $i->received_quantity, 'outstanding' => $i->outstanding(), 'unit_cost' => (float) $i->unit_cost,
            ])->all(),
            'cost_variance' => round($variance, 2),
            'receipts' => $po->receipts()->get(['id', 'number', 'received_on', 'total_cost', 'amount_paid']),
        ];
    }
}

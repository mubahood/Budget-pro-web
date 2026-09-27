<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\SaleRecord;
use App\Models\SaleRecordItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Illuminate\Support\Facades\DB;

/**
 * Returns & refunds with ledger symmetry (plan A1/A3, P2-6): returned lines put
 * stock back (optional per line), the sale's net value drops, and only money the
 * customer had actually paid beyond the new net is refunded — as a negative
 * payment with a contra Income row. Credit sales simply owe less.
 */
class ReturnService
{
    public function __construct(
        private readonly StockService $stock = new StockService(),
        private readonly PaymentService $payments = new PaymentService(),
    ) {
    }

    /** Refund held for an exchange by the last create() with refund_to 'hold' (ReturnService::exchange). */
    public float $held = 0.0;

    /** The code of a gift card issued by the last refund (shown once; null when none, or the cashier typed it). */
    public ?string $issuedCode = null;

    /**
     * @param  array<int, array{sale_item_id: int, quantity: float|string, restock?: bool}>  $lines
     * @param  array{refund_to?: ?string, gift_card_code?: ?string}  $options  A9 (additive): refund_to 'store_credit' | 'new_gift_card'
     *                                                                         | 'hold' (exchange); absent = money back by $refundMethod, as before.
     *                                                                         Value paid with a gift card, points or store credit always goes back there first.
     */
    public function create(SaleRecord $sale, array $lines, int $userId, ?string $reason = null, string $refundMethod = 'cash', ?string $clientUuid = null, ?int $shiftId = null, array $options = []): SaleReturn
    {
        if ($clientUuid) {
            $existing = SaleReturn::withoutGlobalScopes()->where('company_id', $sale->company_id)->where('uuid', $clientUuid)->first();
            if ($existing) {
                return $existing->load('items');
            }
        }
        if ($sale->voided_at !== null) {
            throw BusinessRuleException::make('sale_voided', 'This sale was voided; there is nothing to return.');
        }
        if ($lines === []) {
            throw BusinessRuleException::make('empty_return', 'Choose at least one item to return.');
        }
        if ((float) $sale->total_amount < 0) {
            throw BusinessRuleException::make('is_return', 'This is already a return; there is nothing to return on it.');
        }
        $this->held = 0.0;
        $this->issuedCode = null;

        return DB::transaction(function () use ($sale, $lines, $userId, $reason, $refundMethod, $clientUuid, $shiftId, $options) {
            $sale = SaleRecord::withoutGlobalScopes()->lockForUpdate()->find($sale->id);
            $return = new SaleReturn();
            if ($clientUuid) {
                $return->uuid = $clientUuid;
            }
            $return->company_id = $sale->company_id;
            $return->sale_record_id = $sale->id;
            $return->reason = $reason;
            $return->refund_method = \App\Models\Payment::normalizeMethod($refundMethod);
            $return->shift_id = $shiftId;
            $return->created_by_id = $userId;
            $return->save();

            $value = 0.0;
            foreach ($lines as $l) {
                /** @var SaleRecordItem|null $item */
                $item = SaleRecordItem::withoutGlobalScopes()->where('sale_record_id', $sale->id)->lockForUpdate()->find($l['sale_item_id']);
                if ($item === null) {
                    throw BusinessRuleException::make('line_not_found', 'That item is not part of this sale.');
                }
                $qty = round((float) $l['quantity'], 3);
                $returnable = round((float) $item->quantity - (float) $item->returned_quantity, 3);
                if ($qty <= 0 || $qty > $returnable) {
                    throw BusinessRuleException::make('invalid_return_quantity', "You can return at most {$returnable} of {$item->item_name}.", ['returnable' => $returnable]);
                }
                // The line total carries any tax added on top (tax classes, F1), so the refund takes back its share of the tax too.
                $lineValue = round((float) $item->line_total * $qty / max((float) $item->quantity, 0.001), 2);
                $restock = (bool) ($l['restock'] ?? true);
                $movementId = null;
                $product = \App\Models\StockItem::withoutGlobalScopes()->find($item->stock_item_id);
                if ($restock && $product && $product->track_stock !== false) {
                    $baseQty = round($qty * max((float) ($item->unit_factor ?: 1), 0.001), 3);
                    $movementId = $this->stock->record([
                        'stock_item_id' => (int) $item->stock_item_id, 'type' => 'Return', 'quantity' => $baseQty,
                        'unit_cost' => (float) $product->buying_price, 'description' => 'Return on sale '.($sale->receipt_number ?: '#'.$sale->id).($reason ? ': '.$reason : ''),
                        'created_by_id' => $userId, 'reference_type' => 'sale_return', 'reference_id' => $return->id, 'sale_record_id' => $sale->id,
                    ])->id;
                }
                // The line's profit shrinks with what came back.
                $item->profit = round((float) $item->profit - (float) $item->profit * $qty / max($returnable, 0.001), 2);
                $item->returned_quantity = round((float) $item->returned_quantity + $qty, 3);
                $item->saveQuietlySynced();

                SaleReturnItem::create([
                    'company_id' => $sale->company_id, 'sale_return_id' => $return->id, 'sale_record_item_id' => $item->id, 'stock_item_id' => $item->stock_item_id,
                    'quantity' => $qty, 'value' => $lineValue, 'restock' => $restock, 'stock_record_id' => $movementId,
                ]);
                $value += $lineValue;
            }

            $value = round($value, 2);
            $sale->refunded_amount = round((float) $sale->refunded_amount + $value, 2);
            $sale->saveQuietlySynced();

            // Refund only what was actually paid beyond the reduced net (money taken at the till on
            // sales from before payment rows existed counts as paid).
            $this->payments->adoptPaidAtSale($sale);
            $net = max(0, round((float) $sale->total_amount - (float) $sale->refunded_amount, 2));
            $paid = round((float) \App\Models\Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->sum('amount'), 2);
            $refund = max(0, round($paid - $net, 2));
            $to = null;
            if ($refund > 0) {
                $to = $this->settleRefund($sale, $refund, $refundMethod, $userId, $shiftId, $clientUuid, $options);
            }
            $this->payments->syncSaleTotals($sale->fresh());
            if ($sale->customer_id) {
                (new CustomerService())->recalc((int) $sale->customer_id);
            }
            // Loyalty points the sale earned (C1): its share goes back (all of it once everything is returned).
            $full = (float) $sale->refunded_amount >= (float) $sale->total_amount - 0.005;
            (new LoyaltyService())->reverseForSale($sale, $full ? null : ((float) $sale->total_amount > 0 ? $value / (float) $sale->total_amount : 0.0), $userId);

            $return->value = $value;
            $return->refund_amount = $refund;
            if ($to !== null) {
                $return->refund_method = $to;
            }
            $return->saveQuietlySynced();
            \App\Services\Fiscal\FiscalService::queueReturn($return); // F2: credit note after commit, where the country needs one; off = nothing

            return $return->load('items');
        });
    }

    /**
     * Where the money of a return goes: back to the gift cards, points and store credit that paid the sale
     * first, then the rest by $options['refund_to'] (store credit, a new gift card, held for an exchange) or,
     * as before, by $refundMethod from the drawer. Returns the tender key when the rest went to one, else null.
     */
    private function settleRefund(SaleRecord $sale, float $refund, string $refundMethod, int $userId, ?int $shiftId, ?string $clientUuid, array $options): ?string
    {
        $left = round($refund, 2);
        $tenders = new TenderService();
        $to = null;
        $back = $tenders->tenderedOn($sale); // [] for a sale paid only in money: nothing below runs
        foreach ((array) ($back['gift_cards'] ?? []) as $cardId => $spent) {
            if ($left <= 0) {
                break;
            }
            $amt = round(min($left, (float) $spent), 2);
            $tenders->refundTo($sale, 'gift_card', $amt, $userId, $shiftId, ['gift_card_id' => (int) $cardId]);
            $left = round($left - $amt, 2);
            $to = 'gift_card';
        }
        foreach (['points', 'store_credit'] as $kind) {
            if ($left > 0 && isset($back[$kind])) {
                $amt = round(min($left, $back[$kind]), 2);
                $tenders->refundTo($sale, $kind, $amt, $userId, $shiftId);
                $left = round($left - $amt, 2);
                $to = $kind;
            }
        }
        if ($left <= 0) {
            return $to;
        }
        $dest = $options['refund_to'] ?? null;
        if ($dest === 'hold') {
            $this->held = $left;

            return 'exchange';
        }
        if ($dest === 'store_credit' || $dest === 'new_gift_card') {
            if (! GiftCardService::enabled(\App\Models\Company::withoutGlobalScopes()->find($sale->company_id))) {
                throw BusinessRuleException::make('feature_off', 'Store credit and gift cards are not switched on for this shop.');
            }
            $tenders->refundTo($sale, $dest, $left, $userId, $shiftId, ['code' => $options['gift_card_code'] ?? null], $this->issuedCode);

            return $dest === 'new_gift_card' ? 'gift_card' : 'store_credit';
        }
        $this->payments->refund($sale, $left, $refundMethod, $userId, $shiftId, $clientUuid ? \App\Support\Sync\SyncSequence::childUuid($clientUuid, 'refund') : null);

        return $to;
    }

    /**
     * An exchange (A9, `exchanges` feature): return lines of $sale and sell $newSale (a checkout payload:
     * items, payments, …) in one go. The refund the return is owed pays for the new sale first ("Exchange
     * credit", no money moves); the customer pays only the difference by $newSale['payments'], or gets the
     * rest back by $refundMethod.
     *
     * @return array{return: SaleReturn, sale: SaleRecord, credit: float, refund: float}
     */
    public function exchange(SaleRecord $sale, array $lines, int $userId, array $newSale, ?string $reason = null, string $refundMethod = 'cash', ?int $shiftId = null): array
    {
        $company = \App\Models\Company::withoutGlobalScopes()->find($sale->company_id);
        if (! \App\Support\StoreFeatures::enabled($company, 'exchanges')) {
            throw BusinessRuleException::make('feature_off', 'Exchanges are not switched on for this shop.');
        }

        return DB::transaction(function () use ($sale, $lines, $userId, $newSale, $reason, $refundMethod, $shiftId) {
            $return = $this->create($sale, $lines, $userId, $reason ?: 'Exchange', $refundMethod, null, $shiftId, ['refund_to' => 'hold']);
            $held = $this->held;
            $payments = array_values((array) ($newSale['payments'] ?? []));
            if ($held > 0) {
                TenderService::$exchangeHolds[(int) $return->id] = $held;
                array_unshift($payments, ['tender' => 'exchange', 'method' => 'exchange', 'amount' => $held, 'return_id' => (int) $return->id]);
            }
            $newSale['payments'] = $payments;
            $newSale['payments_explicit'] = true;
            $newSale['customer_id'] = $newSale['customer_id'] ?? $sale->customer_id;
            $newSale['shift_id'] = $newSale['shift_id'] ?? $shiftId;
            $newSale['notes'] = trim(($newSale['notes'] ?? '').' Exchange for '.($sale->receipt_number ?: 'sale #'.$sale->id).'.');
            try {
                $new = (new SaleService())->checkout((int) $sale->company_id, $userId, $newSale)['sale'];
            } finally {
                unset(TenderService::$exchangeHolds[(int) $return->id]);
            }

            $used = round((float) \App\Models\Payment::withoutGlobalScopes()->where('sale_record_id', $new->id)->where('method', 'exchange')->sum('amount'), 2);
            $old = SaleRecord::withoutGlobalScopes()->find($sale->id);
            if ($used > 0) {
                $this->payments->recordTender($old, 'exchange', -$used, ['reference' => $new->receipt_number, 'received_by_id' => $userId, 'shift_id' => $shiftId,
                    'notes' => 'Exchanged for sale '.($new->receipt_number ?: '#'.$new->id)]);
            }
            $cash = round($held - $used, 2);
            if ($cash > 0) {
                $this->payments->refund($old, $cash, $refundMethod, $userId, $shiftId);
            }
            $this->payments->syncSaleTotals($old->fresh());
            if ($old->customer_id) {
                (new CustomerService())->recalc((int) $old->customer_id);
            }
            $return->refund_amount = $cash;
            $return->refund_method = $cash > 0 ? \App\Models\Payment::normalizeMethod($refundMethod) : 'exchange';
            $return->saveQuietlySynced();

            return ['return' => $return, 'sale' => $new, 'credit' => $used, 'refund' => $cash];
        });
    }

    /**
     * The lowest price a product sold at in the last 30 days (per base unit, after discounts), or its price
     * now when that is lower: what a return without a receipt pays back.
     */
    public static function lowestRecentPrice(int $companyId, int $stockItemId): float
    {
        $since = \App\Support\LocalDate::today($companyId)->subDays(30)->toDateString();
        $sold = DB::table('sale_record_items as i')->join('sale_records as r', 'r.id', '=', 'i.sale_record_id')
            ->where('r.company_id', $companyId)->where('i.stock_item_id', $stockItemId)->whereNull('r.voided_at')->where('r.status', '<>', 'Voided')
            ->where('r.sale_date', '>=', $since)->where('i.quantity', '>', 0)->where('i.line_total', '>', 0)
            ->min(DB::raw('i.line_total / i.quantity / COALESCE(NULLIF(i.unit_factor, 0), 1)'));
        $now = (float) DB::table('stock_items')->where('company_id', $companyId)->where('id', $stockItemId)->value('selling_price');
        $prices = array_filter([$sold !== null ? (float) $sold : null, $now > 0 ? $now : null], fn ($v) => $v !== null && $v > 0);

        return $prices === [] ? 0.0 : round(min($prices), 2);
    }

    /**
     * A return without a receipt (A9, `exchanges` feature): always with a supervisor's approval (ApprovalService,
     * action `refund`, consumed here), priced at lowestRecentPrice(), goods back on the shelf. It is kept as a
     * return document of its own (a sale with negative lines, status Refunded) so sales, profit and cash all
     * drop by what went back. Money back by $refundTo: a payment method, 'store_credit' (needs $customerId) or
     * 'new_gift_card'.
     *
     * @param  array<int, array{stock_item_id: int, quantity: float|string}>  $lines
     */
    public function noReceipt(int $companyId, int $userId, array $lines, int $approvalId, ?string $reason, string $refundTo = 'cash', ?int $customerId = null, ?int $shiftId = null, ?string $giftCardCode = null): SaleReturn
    {
        $company = \App\Models\Company::withoutGlobalScopes()->find($companyId);
        if (! \App\Support\StoreFeatures::enabled($company, 'exchanges')) {
            throw BusinessRuleException::make('feature_off', 'Returns without a receipt are not switched on for this shop.');
        }
        $lines = array_values(array_filter($lines, fn ($l) => (float) ($l['quantity'] ?? 0) > 0));
        if ($lines === []) {
            throw BusinessRuleException::make('empty_return', 'Choose at least one item to return.');
        }
        if (in_array($refundTo, ['store_credit', 'new_gift_card'], true) && ! GiftCardService::enabled($company)) {
            throw BusinessRuleException::make('feature_off', 'Store credit and gift cards are not switched on for this shop.');
        }
        if ($refundTo === 'store_credit' && ! $customerId) {
            throw BusinessRuleException::make('customer_required', 'Choose the customer who gets the store credit.');
        }
        $this->issuedCode = null;

        return DB::transaction(function () use ($companyId, $userId, $lines, $approvalId, $reason, $refundTo, $customerId, $shiftId, $giftCardCode) {
            (new ApprovalService())->consume($companyId, $approvalId, 'refund', $userId);
            $customer = $customerId ? \App\Models\Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->find($customerId) : null;
            if ($customerId && $customer === null) {
                throw BusinessRuleException::make('customer_not_found', 'Customer not found.');
            }
            $doc = new SaleRecord();
            $doc->company_id = $companyId;
            $doc->created_by_id = $userId;
            $doc->sale_date = \App\Support\LocalDate::today($companyId);
            $doc->customer_id = $customer?->id;
            $doc->customer_name = $customer?->name ?? 'Walk-in Customer';
            $doc->customer_phone = $customer?->phone;
            $doc->payment_method = in_array($refundTo, ['store_credit', 'new_gift_card'], true) ? $refundTo : \App\Models\Payment::normalizeMethod($refundTo);
            $doc->notes = 'Return without a receipt'.($reason ? ': '.$reason : '');
            $doc->shift_id = $shiftId;
            $doc->save();

            $return = new SaleReturn();
            $return->company_id = $companyId;
            $return->sale_record_id = $doc->id;
            $return->reason = $reason ?: 'No receipt';
            $return->refund_method = $doc->payment_method;
            $return->shift_id = $shiftId;
            $return->created_by_id = $userId;
            $return->save();

            $value = 0.0;
            foreach ($lines as $l) {
                $product = \App\Models\StockItem::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->find((int) $l['stock_item_id']);
                if ($product === null) {
                    throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
                }
                $qty = round((float) $l['quantity'], 3);
                $price = self::lowestRecentPrice($companyId, (int) $product->id);
                $lineValue = round($qty * $price, 2);
                $cost = round((float) $product->buying_price * $qty, 2);
                $item = new SaleRecordItem();
                $item->company_id = $companyId;
                $item->sale_record_id = $doc->id;
                $item->stock_item_id = $product->id;
                $item->quantity = $qty;
                $item->unit_price = $price > 0 ? $price : 0.01;
                $item->unit_cost = (float) $product->buying_price;
                $item->save();
                // Negative line: a return, not a sale (sales, quantity sold and profit drop by it).
                $item->quantity = -$qty;
                $item->unit_price = $price;
                $item->subtotal = -$lineValue;
                $item->discount_amount = 0;
                $item->line_total = -$lineValue;
                $item->profit = round(-($lineValue - $cost), 2);
                $item->saveQuietlySynced();

                $movementId = null;
                if ($product->track_stock !== false) {
                    $movementId = $this->stock->record([
                        'stock_item_id' => (int) $product->id, 'type' => 'Return', 'quantity' => $qty, 'unit_cost' => (float) $product->buying_price,
                        'description' => 'Return without a receipt '.($doc->receipt_number ?: '#'.$doc->id).($reason ? ': '.$reason : ''),
                        'created_by_id' => $userId, 'reference_type' => 'sale_return', 'reference_id' => $return->id, 'sale_record_id' => $doc->id,
                    ])->id;
                }
                SaleReturnItem::create([
                    'company_id' => $companyId, 'sale_return_id' => $return->id, 'sale_record_item_id' => $item->id, 'stock_item_id' => $product->id,
                    'quantity' => $qty, 'value' => $lineValue, 'restock' => true, 'stock_record_id' => $movementId,
                ]);
                $value += $lineValue;
            }
            $value = round($value, 2);
            if ($value <= 0) {
                throw BusinessRuleException::make('no_price', 'These items have no price to refund.');
            }
            $doc->subtotal = -$value;
            $doc->total_amount = -$value;
            $doc->processed_at = now();
            $doc->saveQuietlySynced();

            if (in_array($refundTo, ['store_credit', 'new_gift_card'], true)) {
                (new TenderService())->refundTo($doc, $refundTo, $value, $userId, $shiftId, ['code' => $giftCardCode], $this->issuedCode);
            } else {
                $this->payments->refund($doc, $value, $refundTo, $userId, $shiftId);
            }
            // The document's own figures: money went out, nothing is owed either way.
            $doc = SaleRecord::withoutGlobalScopes()->find($doc->id);
            $doc->amount_paid = -$value;
            $doc->balance = 0;
            $doc->change_given = 0;
            $doc->payment_status = 'Paid';
            $doc->status = 'Refunded';
            $doc->saveQuietlySynced();
            if ($doc->customer_id) {
                (new CustomerService())->recalc((int) $doc->customer_id);
            }

            $return->value = $value;
            $return->refund_amount = $value;
            $return->saveQuietlySynced();

            return $return->load('items');
        });
    }
}

<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialPeriod;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Models\SaleRecordItem;
use App\Models\StockRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One code path for a sale — API checkout, admin POS, sync push (P0-5..P0-8).
 *
 *  - one DB transaction for header + lines + movements + payments + ledger
 *  - products locked in id order (no deadlocks), stock decremented atomically
 *  - idempotent on (company_id, client_uuid)
 *  - discounts flow into movements and the ledger; income is posted per payment
 *  - per-company receipt/invoice numbers; period derived from the sale date
 *  - void = contra movements + contra payments, nothing hard-deleted
 */
class SaleService
{
    public function __construct(
        private readonly StockService $stock = new StockService(),
        private readonly PaymentService $payments = new PaymentService(),
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  items[{stock_item_id, quantity, unit_price?, discount_amount?, unit_id?}], payments[], amount_paid, payment_method,
     *                                      discount_*, customer_*, customer_id, shift_id, sale_date, notes, client_uuid, provisional_number, device_id,
     *                                      allow_negative_stock, payments_explicit, from_sync, location_id
     * @return array{sale: SaleRecord, replayed: bool}
     */
    public function checkout(int $companyId, int $userId, array $data): array
    {
        $clientUuid = $data['client_uuid'] ?? null;
        if ($clientUuid) {
            $existing = SaleRecord::withoutGlobalScopes()->where('company_id', $companyId)->where('client_uuid', $clientUuid)->first();
            if ($existing) {
                return ['sale' => $this->loaded($existing), 'replayed' => true];
            }
        }

        $sale = DB::transaction(function () use ($companyId, $userId, $data, $clientUuid) {
            $saleDate = isset($data['sale_date']) ? Carbon::parse($data['sale_date']) : now();
            $period = $this->periodFor($companyId, $saleDate);

            $sale = new SaleRecord();
            $sale->client_uuid = $clientUuid;
            $sale->company_id = $companyId;
            $sale->financial_period_id = $period->id;
            $sale->created_by_id = $userId;
            $sale->sale_date = $saleDate;
            $sale->customer_name = $data['customer_name'] ?? 'Walk-in Customer';
            $sale->customer_phone = $data['customer_phone'] ?? null;
            $sale->customer_address = $data['customer_address'] ?? null;
            $sale->payment_method = $data['payment_method'] ?? 'Cash';
            $sale->discount_amount = round((float) ($data['discount_amount'] ?? 0), 2);
            $sale->discount_reason = $data['discount_reason'] ?? null;
            $sale->notes = $data['notes'] ?? null;
            $sale->status = 'Completed';
            $sale->currency = Company::withoutGlobalScopes()->find($companyId)?->currency;
            $sale->provisional_number = $data['provisional_number'] ?? null; // offline receipt ref (Appendix D)
            $sale->device_id = $data['device_id'] ?? null;
            $customer = $this->resolveCustomer($companyId, $userId, $data);
            if ($customer) {
                $sale->customer_id = $customer->id;
                if (empty($data['customer_name']) || $sale->customer_name === 'Walk-in Customer') {
                    $sale->customer_name = $customer->name;
                }
                $sale->customer_phone = $sale->customer_phone ?: $customer->phone;
            }
            if (! empty($data['shift_id'])) {
                $shift = \App\Models\Shift::withoutGlobalScopes()->where('company_id', $companyId)->find($data['shift_id']);
                if ($shift === null) {
                    throw BusinessRuleException::make('shift_not_found', 'Shift not found.');
                }
                if ($shift->status !== 'open' && empty($data['from_sync'])) {
                    throw BusinessRuleException::make('shift_closed', 'That shift is already closed. Open a new shift to keep selling.');
                }
                $sale->shift_id = $shift->id;
            }
            $sale->skipNumbering = true; // numbers are assigned in finalize(), inside this transaction
            $sale->save();

            foreach ($data['items'] as $line) {
                $item = new SaleRecordItem();
                $item->company_id = $companyId;
                $item->sale_record_id = $sale->id;
                $item->stock_item_id = (int) $line['stock_item_id'];
                if (! empty($line['unit_id'])) {
                    $unit = \App\Models\Unit::withoutGlobalScopes()->where('company_id', $companyId)->find($line['unit_id']);
                    if ($unit === null) {
                        throw BusinessRuleException::make('unit_not_found', 'Unit not found.');
                    }
                    $item->unit_id = $unit->id;
                    $item->unit_factor = max(0.001, (float) $unit->factor);
                }
                $item->quantity = $line['quantity'];
                $item->unit_price = array_key_exists('unit_price', $line) && $line['unit_price'] !== null ? $line['unit_price'] : null;
                $item->discount_amount = round((float) ($line['discount_amount'] ?? 0), 2);
                $item->save();
            }

            $payments = $data['payments'] ?? null;
            if ($payments === null) {
                $amountPaid = round((float) ($data['amount_paid'] ?? 0), 2);
                $payments = $amountPaid > 0 ? [['method' => $data['payment_method'] ?? 'Cash', 'amount' => $amountPaid]] : [];
            }

            // Money owed must be owed by someone: explicit-payment clients (POS, API v1) must
            // name a customer for credit; the legacy amount_paid path and offline sync stay lenient.
            $this->creditRules = [
                'require_customer' => ! empty($data['payments_explicit']) && empty($data['from_sync']),
                'enforce_limit' => empty($data['from_sync']),
            ];

            $location = isset($data['location_id']) ? (int) $data['location_id'] : null;
            if ($location) {
                LocationStock::assertLocation($companyId, $location);
            }

            return $this->finalize($sale->fresh(), $payments, $userId, (bool) ($data['allow_negative_stock'] ?? false), $location);
        });

        return ['sale' => $this->loaded($sale), 'replayed' => false];
    }

    /**
     * Apply stock, totals, numbering and payments to a header + lines that were
     * persisted by someone else (admin POS form, legacy paths, tests).
     * Safe to call twice: a processed sale is returned untouched.
     */
    public function processExistingSale(SaleRecord $sale, ?array $payments = null, ?int $userId = null): SaleRecord
    {
        if ($sale->processed_at !== null) {
            return $sale;
        }

        return DB::transaction(function () use ($sale, $payments, $userId) {
            if ($payments === null) {
                $amountPaid = round((float) $sale->amount_paid, 2);
                $payments = $amountPaid > 0 ? [['method' => $sale->payment_method ?? 'Cash', 'amount' => $amountPaid]] : [];
            }

            return $this->finalize($sale, $payments, $userId ?? (int) $sale->created_by_id, false);
        });
    }

    /** Reverse every movement and payment of a sale; keeps all rows (contra entries). */
    public function void(SaleRecord $sale, ?string $reason, int $userId): SaleRecord
    {
        if ($sale->voided_at !== null) {
            return $this->loaded($sale);
        }

        return DB::transaction(function () use ($sale, $reason, $userId) {
            $movements = StockRecord::withoutGlobalScopes()->where('sale_record_id', $sale->id)->where('is_reversal', false)->get();
            foreach ($movements as $movement) {
                $this->stock->reverse($movement, $reason ?? 'Sale voided', $userId);
            }
            $payments = Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->where('is_reversal', false)->get();
            foreach ($payments as $payment) {
                $this->payments->reverse($payment, $reason ?? 'Sale voided', $userId);
            }

            $sale->status = 'Voided';
            $sale->voided_at = now();
            $sale->voided_by_id = $userId;
            $sale->voided_reason = $reason;
            $sale->saveQuietlySynced();
            $this->payments->syncSaleTotals($sale);
            $sale->status = 'Voided';
            $sale->saveQuietlySynced();
            if ($sale->customer_id) {
                (new CustomerService())->recalc((int) $sale->customer_id);
            }

            return $this->loaded($sale);
        });
    }

    public function addPayment(SaleRecord $sale, array $attrs, int $userId): Payment
    {
        if ($sale->voided_at !== null) {
            throw BusinessRuleException::make('sale_voided', 'This sale has been voided; it cannot receive payments.');
        }
        $attrs['received_by_id'] = $attrs['received_by_id'] ?? $userId;

        return $this->payments->record($sale, $attrs);
    }

    // ------------------------------------------------------------------

    /** @var array{require_customer: bool, enforce_limit: bool} */
    private array $creditRules = ['require_customer' => false, 'enforce_limit' => false];

    /** customer_id, or find-or-create by phone when a named buyer is given (debt book). */
    private function resolveCustomer(int $companyId, int $userId, array $data): ?\App\Models\Customer
    {
        if (! empty($data['customer_id'])) {
            $c = \App\Models\Customer::withoutGlobalScopes()->where('company_id', $companyId)->find($data['customer_id']);
            if ($c === null) {
                throw BusinessRuleException::make('customer_not_found', 'Customer not found.');
            }

            return $c;
        }
        $phone = trim((string) ($data['customer_phone'] ?? ''));
        $name = trim((string) ($data['customer_name'] ?? ''));
        if ($phone === '' || $name === '' || strcasecmp($name, 'Walk-in Customer') === 0) {
            return null;
        }
        $c = \App\Models\Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('phone', $phone)->first();
        if ($c === null) {
            $c = new \App\Models\Customer();
            $c->company_id = $companyId;
            $c->name = $name;
            $c->phone = $phone;
            $c->created_by_id = $userId;
            $c->save();
        }

        return $c;
    }

    private function finalize(SaleRecord $sale, array $payments, int $userId, bool $allowNegative, ?int $locationId = null): SaleRecord
    {
        $sale->load('saleRecordItems');
        $lines = $sale->saleRecordItems;
        if ($lines->isEmpty()) {
            throw BusinessRuleException::make('empty_sale', 'A sale needs at least one item.');
        }

        // Lock every product in deterministic order first (deadlock-free).
        $ids = $lines->pluck('stock_item_id')->map(fn ($v) => (int) $v)->unique()->sort()->values();
        $products = [];
        foreach ($ids as $id) {
            $products[$id] = StockService::lock($id);
            if ((int) $products[$id]->company_id !== (int) $sale->company_id) {
                throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
            }
        }

        // Line maths.
        $subtotal = 0.0;
        $netBeforeHeaderDiscount = 0.0;
        foreach ($lines as $line) {
            $product = $products[(int) $line->stock_item_id];
            $qty = round((float) $line->quantity, 3);
            if ($qty <= 0) {
                throw BusinessRuleException::make('invalid_quantity', 'Quantity must be greater than zero.');
            }
            $factor = max(0.001, (float) ($line->unit_factor ?: 1));
            $unitPrice = $line->unit_price === null ? round((float) $product->selling_price * $factor, 2) : (float) $line->unit_price;
            if ($unitPrice < 0) {
                throw BusinessRuleException::make('invalid_price', 'Unit price cannot be negative.');
            }
            $lineSub = round($qty * $unitPrice, 2);
            $lineDiscount = min(round((float) $line->discount_amount, 2), $lineSub);

            $line->item_name = $product->name;
            $line->item_sku = $product->sku ?? '';
            $line->unit_cost = round((float) $product->buying_price * $factor, 2); // per sold unit
            $line->unit_price = $unitPrice;
            $line->subtotal = $lineSub;
            $line->discount_amount = $lineDiscount;
            $line->line_total = round($lineSub - $lineDiscount, 2);
            $subtotal += $lineSub;
            $netBeforeHeaderDiscount += $line->line_total;
        }

        // Header discount allocated pro-rata; the last line absorbs rounding.
        $headerDiscount = min(round((float) $sale->discount_amount, 2), round($netBeforeHeaderDiscount, 2));
        $allocated = 0.0;
        $count = $lines->count();
        foreach ($lines as $i => $line) {
            if ($headerDiscount > 0 && $netBeforeHeaderDiscount > 0) {
                $share = ($i === $count - 1)
                    ? round($headerDiscount - $allocated, 2)
                    : round($headerDiscount * ((float) $line->line_total / $netBeforeHeaderDiscount), 2);
                $allocated += $share;
                $line->line_total = round((float) $line->line_total - $share, 2);
            }
            $line->profit = round((float) $line->line_total - ((float) $line->unit_cost * (float) $line->quantity), 2);
            $line->saveQuietlySynced();
        }

        $total = round(array_sum($lines->map(fn ($l) => (float) $l->line_total)->all()), 2);

        // Credit rules (plan A3): a balance needs a customer; stay within their credit limit.
        $paying = round(array_sum(array_map(fn ($p) => max(0, (float) ($p['amount'] ?? 0)), $payments)), 2);
        $owed = max(0, round($total - $paying, 2));
        if ($owed > 0) {
            if ($this->creditRules['require_customer'] && empty($sale->customer_id)) {
                throw BusinessRuleException::make('customer_required', 'Choose a customer for a sale on credit.');
            }
            if ($this->creditRules['enforce_limit'] && $sale->customer_id) {
                $customer = \App\Models\Customer::withoutGlobalScopes()->find($sale->customer_id);
                if ($customer && $customer->credit_limit !== null) {
                    $after = round((float) (new CustomerService())->balance($customer) + $owed, 2);
                    if ($after > (float) $customer->credit_limit) {
                        throw BusinessRuleException::make('credit_limit_exceeded', $customer->name.' would owe '.number_format($after, 2).', above the credit limit of '.number_format((float) $customer->credit_limit, 2).'.',
                            ['credit_limit' => (float) $customer->credit_limit, 'balance_after' => $after]);
                    }
                }
            }
        }

        // Movements (stock + profit at the *effective* price), in base units.
        foreach ($lines as $line) {
            $product = $products[(int) $line->stock_item_id];
            if ($product->track_stock === false) {
                continue; // services / non-stock items: no movement
            }
            $factor = max(0.001, (float) ($line->unit_factor ?: 1));
            $qty = round((float) $line->quantity * $factor, 3);
            $movement = $this->stock->record([
                'stock_item_id' => (int) $line->stock_item_id,
                'type' => 'Sale',
                'quantity' => $qty,
                'selling_price' => $qty > 0 ? round((float) $line->line_total / $qty, 4) : 0,
                'unit_cost' => (float) $product->buying_price,
                'description' => 'Sale '.($sale->receipt_number ?: '#'.$sale->id).' - '.($sale->customer_name ?? 'Walk-in Customer'),
                'date' => $sale->sale_date,
                'created_by_id' => $userId,
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'sale_record_id' => $sale->id,
                'location_id' => $locationId,
                'allow_negative' => $allowNegative || (bool) $products[(int) $line->stock_item_id]->allow_negative_stock,
            ]);
            $line->stock_record_id = $movement->id;
            $line->saveQuietlySynced();
        }

        // Numbers + totals.
        if (empty($sale->receipt_number)) {
            $sale->receipt_number = NumberSequencer::next((int) $sale->company_id, 'receipt', $sale->sale_date);
        }
        if (empty($sale->invoice_number)) {
            $sale->invoice_number = NumberSequencer::next((int) $sale->company_id, 'invoice', $sale->sale_date);
        }
        $sale->subtotal = round($subtotal, 2);
        $sale->discount_amount = $headerDiscount + round(array_sum($lines->map(fn ($l) => (float) $l->discount_amount)->all()), 2);
        $sale->total_amount = $total;
        $sale->amount_paid = 0;
        $sale->balance = $total;
        $sale->payment_status = 'Unpaid';
        $sale->processed_at = now();
        $sale->saveQuietlySynced();

        // Payments -> ledger. Cash over-tender becomes change, not income.
        $remaining = $total;
        foreach ($payments as $p) {
            $amount = round((float) ($p['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $applied = min($amount, max($remaining, 0));
            if ($applied > 0) {
                $this->payments->record($sale, array_merge($p, ['amount' => $applied, 'received_by_id' => $userId]));
            }
            $remaining = round($remaining - $amount, 2);
        }
        if ($remaining < 0) {
            $sale->change_given = round(-$remaining, 2);
            $sale->saveQuietlySynced();
        }
        $this->payments->syncSaleTotals($sale);
        if ($remaining < 0) {
            $sale->change_given = round(-$remaining, 2);
            $sale->saveQuietlySynced();
        }
        if ($sale->customer_id) {
            (new CustomerService())->recalc((int) $sale->customer_id);
        }

        return $sale;
    }

    /** The period the business date falls in; closed periods are refused (P0-10). */
    public function periodFor(int $companyId, Carbon $date): FinancialPeriod
    {
        $period = FinancialPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderByRaw("status = 'Active' DESC")
            ->first();

        if ($period === null) {
            $period = FinancialPeriod::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'Active')->first();
        }
        if ($period === null) {
            throw BusinessRuleException::make('no_active_period', 'No active financial period. Please create/activate one before recording sales.');
        }
        if ($period->status === 'Closed') {
            throw BusinessRuleException::make('period_closed', 'The financial period for '.$date->toDateString().' is closed.', ['period_id' => $period->id]);
        }

        return $period;
    }

    private function loaded(SaleRecord $sale): SaleRecord
    {
        return SaleRecord::withoutGlobalScopes()->with(['saleRecordItems', 'payments'])->find($sale->id);
    }
}

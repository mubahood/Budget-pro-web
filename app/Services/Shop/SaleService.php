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
     *                                      allow_negative_stock, payments_explicit, from_sync, location_id, rounding (A7), age_checked (A10)
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
            // The shop's calendar day (a sale at 01:30 in Kampala is not yesterday's UTC date).
            $saleDate = \App\Support\LocalDate::date($companyId, $data['sale_date'] ?? null);
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
            // Supermarket lane (StoreFeatures): cash rounding (A7, checked in finalize) and the age check (A10). Absent = as before.
            if (isset($data['rounding']) && abs((float) $data['rounding']) >= 0.005) {
                $sale->rounding_amount = round((float) $data['rounding'], 2);
            }
            if (! empty($data['age_checked'])) {
                $sale->age_checked_by = $userId;
            }
            $sale->skipNumbering = true; // numbers are assigned in finalize(), inside this transaction
            $sale->save();

            // Supervisor approval for price overrides (A5, `approvals` feature): re-checked here, whatever the till showed.
            // Price levels and promotions (B2, B3): worked out here from the shop's own rules, never taken from the request.
            // Off (or an offline sale synced later, already priced at its till) = null, and the sale is priced as before.
            $this->pricing = empty($data['from_sync']) ? $this->pricingFor($companyId, $sale, $data) : null;
            $this->fromSync = ! empty($data['from_sync']);
            if (empty($data['from_sync'])) {
                (new PriceOverrideService())->enforce($companyId, $userId, $data['items'], $this->pricing['level'] ?? null, $this->pricing['location'] ?? null);
            }

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
                if (! empty($data['from_sync']) && (float) ($line['promo_discount'] ?? 0) >= 0.005) {
                    // An offline sale priced by its till (B3): the promotions' share of this line's discount, as the till worked it out.
                    $item->promo_discount = min(round((float) $line['promo_discount'], 2), (float) $item->discount_amount);
                }
                $eligible = $this->pricing !== null ? $this->priceLine($item, $line) : false;
                $item->save();
                if ($eligible) {
                    $this->promoLines[$item->id] = true;
                }
                // A markdown label (B4): this line's stock comes out of the marked-down batch first.
                if (! empty($line['markdown_id']) && ($md = MarkdownService::active($companyId, (int) $line['markdown_id'], (int) $item->stock_item_id))) {
                    $this->batchHints[$item->id] = $md['batch_id'];
                }
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
        \App\Services\Fiscal\FiscalService::queueSale($sale); // F2: queued after commit; nothing when `fiscal` is off; never throws

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
            $this->pricing = null; // lines persisted elsewhere keep their own prices (no levels or promotions here)
            $this->fromSync = false;
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
                $contra = $this->stock->reverse($movement, $reason ?? 'Sale voided', $userId);
                $this->reverseLegacyIncome($movement, $contra, $reason, $userId);
            }
            // Undoing the sale movement put back everything sold, including goods that came back faulty
            // and were never restocked: write those off again so they don't reappear on the shelf.
            $faulty = DB::table('sale_return_items as ri')->join('sale_returns as r', 'r.id', '=', 'ri.sale_return_id')
                ->where('r.sale_record_id', $sale->id)->where('r.is_deleted', 0)->where('ri.restock', 0)
                ->get(['ri.stock_item_id', 'ri.quantity', 'ri.sale_record_item_id', 'r.id as return_id']);
            foreach ($faulty as $f) {
                $product = \App\Models\StockItem::withoutGlobalScopes()->find($f->stock_item_id);
                if ($product === null || $product->track_stock === false) {
                    continue;
                }
                $factor = (float) (DB::table('sale_record_items')->where('id', $f->sale_record_item_id)->value('unit_factor') ?: 1);
                $this->stock->record([
                    'stock_item_id' => (int) $f->stock_item_id, 'type' => 'Damage', 'reason' => 'damage', 'quantity' => round((float) $f->quantity * max($factor, 0.001), 3),
                    'unit_cost' => (float) $product->buying_price, 'created_by_id' => $userId, 'allow_negative' => true,
                    'description' => 'Faulty return on voided sale '.($sale->receipt_number ?: '#'.$sale->id),
                    'reference_type' => 'sale_return', 'reference_id' => (int) $f->return_id,
                ]);
            }
            $this->payments->adoptPaidAtSale($sale); // money taken at the till before payment rows existed is handed back too
            $payments = Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->where('is_reversal', false)->get();
            foreach ($payments as $payment) {
                $this->payments->reverse($payment, $reason ?? 'Sale voided', $userId);
            }
            (new LoyaltyService())->reverseForSale($sale, null, $userId); // points the sale earned (none written = nothing)

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

    /**
     * Before Phase 0 every sale movement — also those of a sale document — posted its value as
     * Income (financial_records source_type 'stock_record', source_id = movement id). Voiding such a
     * sale takes that income back with a contra row, the same shape StockRecord::postLegacyLedger
     * writes for a reversed stand-alone sale movement. Rows already reversed are left alone.
     */
    private function reverseLegacyIncome(StockRecord $movement, StockRecord $contra, ?string $reason, int $userId): void
    {
        $rows = \App\Models\FinancialRecord::withoutGlobalScopes()->where('company_id', $movement->company_id)
            ->where('source_type', 'stock_record')->where('source_id', $movement->id)->where('is_reversal', false)->where('is_deleted', false)->where('amount', '!=', 0)
            ->whereNotExists(fn ($q) => $q->from('financial_records as rv')->whereColumn('rv.reverses_id', 'financial_records.id'))
            ->get();
        foreach ($rows as $original) {
            $row = new \App\Models\FinancialRecord();
            $row->financial_category_id = $original->financial_category_id;
            $row->company_id = $original->company_id;
            $row->user_id = $userId;
            $row->created_by_id = $userId;
            $row->amount = -1 * (float) $original->amount;
            $row->quantity = $original->quantity;
            $row->type = $original->type;
            $row->payment_method = $original->payment_method;
            $row->recipient = (string) $original->recipient;
            $row->receipt = (string) $original->receipt;
            $row->date = \App\Support\LocalDate::today((int) $original->company_id);
            $row->description = 'Reversal of sale movement #'.$movement->id.($reason ? ': '.$reason : '');
            $row->source_type = 'stock_record';
            $row->source_id = $contra->id;
            $row->is_reversal = true;
            $row->reverses_id = $original->id;
            $row->currency = $original->currency;
            $row->save();
        }
    }

    /** The only columns of a recorded sale that may change by hand (who bought it, and notes). */
    public const EDITABLE_DETAILS = ['customer_id', 'customer_name', 'customer_phone', 'customer_address', 'notes'];

    /**
     * Change who bought a recorded sale and its notes (the classic admin's edit form). Amounts, status,
     * dates and numbers are derived and are refused. Moving the sale onto another customer also moves
     * its payments, and both customers' balances are recalculated.
     *
     * @param  array<string, mixed>  $attrs  any of EDITABLE_DETAILS
     */
    public function updateDetails(SaleRecord $sale, array $attrs, int $userId): SaleRecord
    {
        if ($sale->voided_at !== null || $sale->status === 'Voided') {
            throw BusinessRuleException::make('sale_voided', 'This sale was voided; its details can no longer be changed.');
        }
        $blocked = array_values(array_diff(array_keys($attrs), self::EDITABLE_DETAILS));
        if ($blocked !== []) {
            throw BusinessRuleException::make('derived_field', 'The '.str_replace('_', ' ', (string) $blocked[0]).' of a recorded sale cannot be changed here. Receive a payment, return items or void the sale instead.', ['field' => $blocked[0]]);
        }
        $companyId = (int) $sale->company_id;
        if (array_key_exists('customer_id', $attrs)) {
            $attrs['customer_id'] = $attrs['customer_id'] ? (int) $attrs['customer_id'] : null;
            if ($attrs['customer_id'] !== null && ! \App\Models\Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->whereKey($attrs['customer_id'])->exists()) {
                throw BusinessRuleException::make('customer_not_found', 'That customer account was not found.');
            }
        }
        if (array_key_exists('customer_phone', $attrs)) {
            $phone = preg_replace('/[^0-9+]/', '', trim((string) $attrs['customer_phone'])) ?? '';
            $attrs['customer_phone'] = $phone !== '' ? $phone : null;
        }
        foreach (['customer_name', 'customer_address', 'notes'] as $text) {
            if (array_key_exists($text, $attrs)) {
                $attrs[$text] = trim((string) $attrs[$text]) !== '' ? trim((string) $attrs[$text]) : null;
            }
        }

        return DB::transaction(function () use ($sale, $attrs, $companyId) {
            $previous = $sale->customer_id ? (int) $sale->customer_id : null;
            $sale->fill($attrs);
            $sale->save();
            $now = $sale->customer_id ? (int) $sale->customer_id : null;
            if ($previous !== $now) {
                // Money received on this sale follows it to the new account (DebtService::adopt does the same).
                DB::table('payments')->where('company_id', $companyId)->where('sale_record_id', $sale->id)
                    ->where(fn ($q) => $q->whereNull('customer_id')->when($previous !== null, fn ($w) => $w->orWhere('customer_id', $previous)))
                    ->update(['customer_id' => $now]);
                $customers = new CustomerService();
                foreach (array_filter([$previous, $now]) as $cid) {
                    $customers->recalc($cid);
                }
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

    /** @var array<int, int> sale line id => batch to take first (a markdown label's batch, B4) */
    private array $batchHints = [];

    /** @var array{levels: bool, promos: bool, level: ?string, customer_id: ?int, coupon: ?string, rows: array, products: array, dept_keys: bool}|null price levels / promotions for this checkout (B2, B3); null = off */
    private ?array $pricing = null;

    /** @var array<int, true> sale line ids promotions may look at */
    private array $promoLines = [];

    /** An offline sale synced from a phone: already priced and rounded at its till (only sanity-checked here). */
    private bool $fromSync = false;

    /** What this checkout needs to price its lines by level and promotion, or null when the shop uses neither. */
    private function pricingFor(int $companyId, SaleRecord $sale, array $data): ?array
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        $levels = PriceLevelService::enabled($company);
        $promos = PromotionService::enabled($company);
        $ids = array_map(fn ($l) => (int) ($l['stock_item_id'] ?? 0), $data['items'] ?? []);
        // Store prices (G1, `store_prices`): the sale's store's own prices replace the selling price; a product it does not sell is refused.
        $location = StorePriceService::enabled($company) ? StorePriceService::saleLocation($companyId, $data) : null;
        if ($location !== null) {
            StorePriceService::assertAvailable($companyId, $location, $ids);
        }
        $store = $location !== null ? PriceLevelService::locationRows($companyId, $location, $ids) : [];
        if (! $levels && ! $promos && $store === []) {
            return null;
        }
        $customerId = $sale->customer_id ? (int) $sale->customer_id : null;

        return ['location' => $location, 'store' => $store,
            'levels' => $levels, 'promos' => $promos, 'level' => $levels ? PriceLevelService::levelOf($companyId, $customerId) : null, 'customer_id' => $customerId,
            'coupon' => isset($data['coupon_code']) && trim((string) $data['coupon_code']) !== '' ? trim((string) $data['coupon_code']) : null,
            'rows' => $levels ? PriceLevelService::rowsFor($companyId, $ids) : [], 'products' => PromotionService::products($companyId, $ids),
            'dept_keys' => \App\Support\StoreFeatures::enabled($company, 'department_keys')];
    }

    /**
     * A line without its own price gets the level price / quantity break (B2). Returns whether promotions may
     * look at it (PromotionService::eligible: not re-priced, discounted, a markdown or an open-price key).
     */
    private function priceLine(SaleRecordItem $item, array $line): bool
    {
        $p = $this->pricing['products'][(int) $item->stock_item_id] ?? null;
        if ($p === null) {
            return false;
        }
        $factor = max(0.001, (float) ($item->unit_factor ?: 1));
        $unitId = $item->unit_id ? (int) $item->unit_id : null;
        $storeRow = $this->pricing['store'][(int) $p->id] ?? null; // the sale's store's own price (G1), before levels
        $catalogue = ($this->pricing['levels'] ? PriceLevelService::pick($this->pricing['rows'][(int) $p->id] ?? [], $unitId, $factor, (float) $item->quantity, $this->pricing['level']) : null)
            ?? PriceLevelService::storeBase($storeRow, (float) $p->selling_price, $unitId, $factor);
        $explicit = $item->unit_price !== null ? round((float) $item->unit_price, 2) : null;
        if ($explicit === null && ($this->pricing['levels'] || $storeRow !== null)) {
            $item->unit_price = $catalogue;
        }

        return $this->pricing['promos'] && PromotionService::eligible($explicit, $catalogue, (float) $item->discount_amount, ! empty($line['markdown_id']), $this->pricing['dept_keys'] && $p->open_price);
    }

    /**
     * Promotions (B3) on the priced lines: each line's share of a promotion's discount becomes part of its
     * discount (promo_discount says how much), so totals, tax, profit and returns all follow; the sale keeps
     * the promotions it got by name (sale_promotions). Returns the lines' net before the header discount.
     */
    private function applyPromotions(SaleRecord $sale, $lines, array $products, float $net): float
    {
        if ($this->pricing === null || ! $this->pricing['promos']) {
            return $net;
        }
        $cart = [];
        foreach ($lines as $line) {
            $cart[$line->id] = PromotionService::lineFor($products[(int) $line->stock_item_id], (float) $line->quantity, max(0.001, (float) ($line->unit_factor ?: 1)),
                (float) $line->unit_price, isset($this->promoLines[$line->id]));
        }
        $r = PromotionService::run((int) $sale->company_id, $cart, ['customer_id' => $this->pricing['customer_id'], 'level' => $this->pricing['level'], 'coupon' => $this->pricing['coupon']]);
        foreach ($lines as $line) {
            $promo = min(round((float) ($r['lines'][$line->id]['discount'] ?? 0), 2), (float) $line->line_total);
            if ($promo < 0.005) {
                continue;
            }
            $line->promo_discount = $promo;
            $line->discount_amount = round((float) $line->discount_amount + $promo, 2);
            $line->line_total = round((float) $line->line_total - $promo, 2);
            $net -= $promo;
        }
        PromotionService::record((int) $sale->company_id, (int) $sale->id, $r['applied']);

        return $net;
    }

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

        // Tax classes (F1, `tax_classes` feature): off = nothing stored, totals as before.
        $company = Company::withoutGlobalScopes()->find($sale->company_id);
        $taxRates = TaxClassService::enabled($company) ? (new TaxClassService())->rates((int) $sale->company_id) : null;
        $taxInclusive = $taxRates === null || TaxClassService::inclusive($company);
        $taxOnTop = []; // line id => tax added on top of the price (exclusive pricing)

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
        // Promotions (B3, `promotions` feature): part of each line's discount, before the whole-sale discount.
        $netBeforeHeaderDiscount = $this->applyPromotions($sale, $lines, $products, $netBeforeHeaderDiscount);

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
            if ($taxRates !== null) {
                $class = TaxClassService::classFor($taxRates, $products[(int) $line->stock_item_id]->tax_class_id);
                $tax = TaxClassService::tax((float) $line->line_total, $class['rate'], $taxInclusive);
                $line->tax_class_id = $class['id'];
                $line->tax_rate = $class['rate'];
                $line->tax_amount = $tax;
                if (! $taxInclusive) { // added on top: part of what the customer pays, not of the shop's takings
                    $taxOnTop[$line->id] = $tax;
                    $line->line_total = round((float) $line->line_total + $tax, 2);
                }
            }
            $line->profit = round((float) $line->line_total - ($taxOnTop[$line->id] ?? 0) - ((float) $line->unit_cost * (float) $line->quantity), 2);
            $line->saveQuietlySynced();
        }

        $total = round(array_sum($lines->map(fn ($l) => (float) $l->line_total)->all()), 2);

        // Cash rounding (A7): only the shop's own rule, only for a sale paid wholly in cash. It is part of the total.
        $rounding = round((float) ($sale->rounding_amount ?? 0), 2);
        if (abs($rounding) >= 0.005 && $this->fromSync) {
            // Synced from a till that rounded offline (its payments are separate ops): accepted unless it makes no sense.
            if ($total + $rounding < 0 || abs($rounding) > $total) {
                throw BusinessRuleException::make('rounding_mismatch', 'The cash rounding on this sale is larger than the sale.', ['rounding' => $rounding]);
            }
            $total = round($total + $rounding, 2);
        } elseif (abs($rounding) >= 0.005) {
            $expected = \App\Support\CashRounding::appliesTo($payments) ? \App\Support\CashRounding::amount($company, $total) : 0.0;
            if (abs($expected - $rounding) > 0.005) {
                throw BusinessRuleException::make('rounding_mismatch', 'The cash rounding on this sale does not match the shop\'s rounding rule. Take payment again.', ['expected' => $expected]);
            }
            $total = round($total + $rounding, 2);
        }

        // Gift card / points / store credit / exchange rows (C1, C2, A9): checked and priced first. None = unchanged.
        $payments = (new TenderService())->prepare($sale, $payments, $total);

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
                'selling_price' => $qty > 0 ? round(((float) $line->line_total - ($taxOnTop[$line->id] ?? 0)) / $qty, 4) : 0,
                'unit_cost' => (float) $product->buying_price,
                'description' => 'Sale '.($sale->receipt_number ?: '#'.$sale->id).' - '.($sale->customer_name ?? 'Walk-in Customer'),
                'date' => $sale->sale_date,
                'created_by_id' => $userId,
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'sale_record_id' => $sale->id,
                'location_id' => $locationId,
                'allow_negative' => $allowNegative || (bool) $products[(int) $line->stock_item_id]->allow_negative_stock,
            ] + (isset($this->batchHints[$line->id]) ? ['batch_out' => $this->batchHints[$line->id]] : []));
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
            if ($applied > 0 && ! empty($p['tender'])) {
                (new TenderService())->apply($sale, $p + ['shift_id' => $sale->shift_id], $applied, $userId); // no income: value already held
            } elseif ($applied > 0) {
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
            (new LoyaltyService())->earnForSale($sale, $userId); // loyalty points (C1): nothing unless the feature is on
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

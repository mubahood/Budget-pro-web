<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\V1\Concerns\StoreFeatureGate;
use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\ZReport;
use App\Services\Shop\ApprovalService;
use App\Services\Shop\GiftCardService;
use App\Services\Shop\HeldCartService;
use App\Services\Shop\MarkdownService;
use App\Services\Shop\ShelfLabelService;
use App\Services\Shop\ShiftService;
use App\Services\Shop\ShortDatedService;
use App\Services\Shop\SmartReorderService;
use App\Services\Shop\SupplierPriceService;
use App\Services\Shop\TaxClassService;
use App\Services\Shop\ZReportService;
use App\Support\LocalDate;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The supermarket till and back office for the phone (SUPERMARKET_PLAN; the web screens use the same
 * services): approvals, X/Z reports, gift cards, held carts, short-dated stock, shelf labels, tax classes,
 * supplier prices / scorecard and drawer cash movements. Each endpoint answers 403 `feature_off` when its
 * StoreFeatures switch is off; permissions follow the web screens.
 */
class SupermarketController extends Controller
{
    use ApiResponse, StoreFeatureGate;

    private function cid(Request $request): int
    {
        return (int) $this->company($request)->id;
    }

    private function uid(Request $request): int
    {
        return (int) $request->user()->id;
    }

    /** A BusinessRuleException as the standard 422 (or 404 for "not found" codes). */
    private function rule(BusinessRuleException $e)
    {
        $status = str_ends_with($e->errorCode(), '_not_found') || $e->errorCode() === 'held_cart_gone' ? 404 : 422;

        return $this->error($e->getMessage(), $status, $e->toErrors());
    }

    // ── Approvals (A5, `approvals`) ─────────────────────────

    /** POST approvals {action, pin, context?: {amount?, pct?, original?, price?, sale_id?, reason?}} → {approval_id, approver} */
    public function approve(Request $request)
    {
        if ($off = $this->featureOff($request, 'approvals')) {
            return $off;
        }
        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', array_keys(ApprovalService::ACTIONS))],
            'pin' => ['required', 'string', 'max:10'],
            'context' => ['nullable', 'array'],
            'context.amount' => ['nullable', 'numeric'],
            'context.sale_id' => ['nullable', 'integer'],
            'context.reason' => ['nullable', 'string', 'max:500'],
        ]);
        $ctx = (array) ($data['context'] ?? []);
        if (! empty($ctx['sale_id'])) {
            if (! DB::table('sale_records')->where('company_id', $this->cid($request))->where('id', $ctx['sale_id'])->exists()) {
                return $this->notFound('Sale not found.');
            }
            $ctx['sale_record_id'] = (int) $ctx['sale_id'];
        }
        try {
            $row = app(ApprovalService::class)->grant($this->cid($request), $data['action'], $data['pin'], $this->uid($request), $ctx);
        } catch (BusinessRuleException $e) {
            return $this->rule($e);
        }

        return $this->created([
            'approval_id' => (int) $row->id, 'action' => $row->action,
            'approver' => ['id' => (int) $row->approver->id, 'name' => $row->approver->name],
            'valid_minutes' => ApprovalService::VALID_MINUTES,
            'required' => app(ApprovalService::class)->required($this->company($request), $data['action'], $ctx),
        ], 'Approved by '.$row->approver->name.'.');
    }

    // ── X / Z reports (E2, `cash_control`) ──────────────────

    /** GET x-report?scope=shift|day&shift_id=&date=&location_id= */
    public function xReport(Request $request)
    {
        if ($off = $this->featureOff($request, 'cash_control')) {
            return $off;
        }
        $data = $request->validate(['scope' => ['nullable', 'in:shift,day'], 'shift_id' => ['nullable', 'integer'], 'date' => ['nullable', 'date_format:Y-m-d'], 'location_id' => ['nullable', 'integer']]);
        $cid = $this->cid($request);
        $today = LocalDate::today($cid)->toDateString();
        $z = new ZReportService();
        try {
            if (($data['scope'] ?? 'shift') === 'day') {
                if ($deny = $this->needsAny($request, 'view_reports')) {
                    return $deny;
                }

                return $this->success($z->figures($cid, $data['date'] ?? $today, $data['location_id'] ?? null), 'X report (day).');
            }
            if ($deny = $this->needsAny($request, 'sell', 'view_reports')) {
                return $deny;
            }
            $shift = ! empty($data['shift_id'])
                ? Shift::withoutGlobalScopes()->where('company_id', $cid)->find($data['shift_id'])
                : (new ShiftService())->current($cid, $this->uid($request), $request->header('X-Device-Id'));
            if ($shift === null) {
                return $this->notFound(! empty($data['shift_id']) ? 'Shift not found.' : 'You have no open shift.');
            }
            if ((int) $shift->opened_by_id !== $this->uid($request) && ! $this->can($request, 'view_reports')) {
                return $this->error('You can see only your own shift.', 403, ['code' => 'forbidden', 'permission' => 'view_reports']);
            }

            return $this->success($z->figures($cid, $today, null, $shift) + ['shift_totals' => (new ShiftService())->totals($shift)], 'X report (shift).');
        } catch (BusinessRuleException $e) {
            return $this->rule($e);
        }
    }

    /** POST z-reports {date, location_id?} — close a shop day (view_reports + manage_finance, like the web). */
    public function zClose(Request $request)
    {
        if ($off = $this->featureOff($request, 'cash_control')) {
            return $off;
        }
        if (($deny = $this->needsAny($request, 'view_reports')) || ($deny = $this->needsAny($request, 'manage_finance'))) {
            return $deny;
        }
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'location_id' => ['nullable', 'integer']]);
        try {
            $z = (new ZReportService())->close($this->cid($request), $data['date'], $this->uid($request), $data['location_id'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->rule($e);
        }

        return $this->created($this->zRow($z, true), 'Day closed: Z report '.$z->number.'.');
    }

    /** GET z-reports?from=&to= — newest first (60 at most). */
    public function zIndex(Request $request)
    {
        if ($off = $this->featureOff($request, 'cash_control')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'view_reports')) {
            return $deny;
        }
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $rows = ZReport::withoutGlobalScopes()->where('company_id', $this->cid($request))
            ->when(! empty($data['from']), fn ($q) => $q->whereDate('business_date', '>=', $data['from']))
            ->when(! empty($data['to']), fn ($q) => $q->whereDate('business_date', '<=', $data['to']))
            ->orderByDesc('business_date')->orderByDesc('id')->limit(60)->get();

        return $this->success($rows->map(fn ($z) => $this->zRow($z, false))->values(), 'Z reports.');
    }

    /** GET z-reports/{id} (JSON) or ?format=pdf (80 mm PDF). */
    public function zShow(Request $request, $id)
    {
        if ($off = $this->featureOff($request, 'cash_control')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'view_reports')) {
            return $deny;
        }
        $z = ZReport::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);
        if ($z === null) {
            return $this->notFound('Z report not found.');
        }
        if ($request->query('format') === 'pdf') {
            $html = view('api.z-report', ['z' => $z, 'f' => (array) $z->totals, 'company' => $this->company($request),
                'closedBy' => DB::table('admin_users')->where('id', $z->closed_by)->value('name')])->render();
            $pdf = app('dompdf.wrapper')->loadHTML($html)->setPaper([0, 0, 226.77, 1190.55]); // 80 mm wide

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$z->number.'.pdf"']);
        }

        return $this->success($this->zRow($z, true), 'Z report.');
    }

    private function zRow(ZReport $z, bool $withTotals): array
    {
        $t = (array) $z->totals;

        return ['id' => (int) $z->id, 'number' => $z->number, 'business_date' => substr((string) $z->business_date, 0, 10), 'location_id' => (int) $z->location_key ?: null,
            'closed_by' => (int) $z->closed_by, 'closed_by_name' => DB::table('admin_users')->where('id', $z->closed_by)->value('name'),
            'created_at' => optional($z->created_at)->toIso8601String(), 'net_sales' => $t['sales']['net'] ?? null, 'sales_count' => $t['sales']['count'] ?? null]
            + ($withTotals ? ['totals' => $t] : []);
    }

    // ── Gift cards (C2, `gift_cards`) ───────────────────────

    /** GET gift-cards/{code} (or ?code=) → {balance, status, last4, expires_at} */
    public function giftCard(Request $request, ?string $code = null)
    {
        if ($off = $this->featureOff($request, 'gift_cards')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'sell')) {
            return $deny;
        }
        $card = (new GiftCardService())->find($this->cid($request), rawurldecode((string) ($code ?? $request->query('code', ''))));
        if ($card === null) {
            return $this->error('No gift card has that code. Check the number and try again.', 404, ['code' => 'gift_card_not_found']);
        }

        return $this->success($this->cardRow($card), 'Gift card.');
    }

    private function cardRow(GiftCard $card): array
    {
        $status = ! $card->is_active ? 'stopped' : ($card->isExpired() ? 'expired' : ((float) $card->balance <= 0 ? 'empty' : 'active'));

        return ['id' => (int) $card->id, 'last4' => $card->last4, 'balance' => round((float) $card->balance, 2), 'status' => $status, 'usable' => $status === 'active',
            'expires_at' => optional($card->expires_at)->toDateString(), 'customer_id' => $card->customer_id ? (int) $card->customer_id : null];
    }

    /** POST gift-cards {amount, method, customer_id?, code?, shift_id?, reference?, client_uuid?} — sell a card; the code is shown once. */
    public function sellGiftCard(Request $request)
    {
        if ($off = $this->featureOff($request, 'gift_cards')) {
            return $off;
        }
        $cid = $this->cid($request);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string', 'max:30'],
            'customer_id' => ['nullable', 'integer'],
            'code' => ['nullable', 'string', 'max:40'],
            'shift_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'client_uuid' => ['nullable', 'string', 'max:36'],
        ]);
        if (! empty($data['customer_id']) && ! DB::table('customers')->where('company_id', $cid)->where('id', $data['customer_id'])->where('is_deleted', 0)->exists()) {
            return $this->error('Customer not found.', 422, ['code' => 'customer_not_found']);
        }
        if (! empty($data['shift_id']) && ! DB::table('shifts')->where('company_id', $cid)->where('id', $data['shift_id'])->exists()) {
            return $this->error('Shift not found.', 422, ['code' => 'shift_not_found']);
        }
        try {
            $r = (new GiftCardService())->sell($cid, $this->uid($request), (float) $data['amount'], $data['method'], array_intersect_key($data, array_flip(['customer_id', 'code', 'shift_id', 'reference', 'client_uuid'])));
        } catch (BusinessRuleException $e) {
            return $this->rule($e);
        }

        return $this->created($this->cardRow($r['card']) + ['code' => $r['code'], 'payment_id' => (int) $r['payment']->id, 'payment_uuid' => $r['payment']->uuid], 'Gift card sold.');
    }

    // ── Held carts (A6, `held_carts`) ───────────────────────

    public function heldIndex(Request $request)
    {
        if ($off = $this->featureOff($request, 'held_carts')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'sell')) {
            return $deny;
        }

        return $this->success((new HeldCartService())->list($this->cid($request)), 'Held carts.');
    }

    /** POST held-carts {state: {lines: [...], ...}, label, customer_id?, location_id?, total?} */
    public function hold(Request $request)
    {
        if ($off = $this->featureOff($request, 'held_carts')) {
            return $off;
        }
        $cid = $this->cid($request);
        $data = $request->validate([
            'state' => ['required', 'array'], 'state.lines' => ['required', 'array', 'min:1', 'max:500'],
            'label' => ['nullable', 'string', 'max:191'], 'customer_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'total' => ['nullable', 'numeric', 'min:0'],
        ]);
        foreach (['customer_id' => 'customers', 'location_id' => 'locations'] as $f => $t) {
            if (! empty($data[$f]) && ! DB::table($t)->where('company_id', $cid)->where('id', $data[$f])->exists()) {
                return $this->error(ucfirst(str_replace('_id', '', $f)).' not found.', 422, ['code' => str_replace('_id', '', $f).'_not_found']);
            }
        }
        $state = $data['state'];
        $total = $data['total'] ?? $state['total'] ?? collect($state['lines'])->sum(fn ($l) => (float) ($l['quantity'] ?? 0) * (float) ($l['unit_price'] ?? 0) - (float) ($l['discount_amount'] ?? 0));
        try {
            $cart = (new HeldCartService())->hold($cid, $this->uid($request), $state, (string) ($data['label'] ?? ''), $data['location_id'] ?? null, $data['customer_id'] ?? null,
                count($state['lines']), (float) $total);
        } catch (BusinessRuleException $e) {
            return $this->rule($e);
        }

        return $this->created(['id' => (int) $cart->id, 'label' => $cart->label, 'count' => (int) $cart->line_count, 'total' => (float) $cart->total,
            'at' => optional($cart->created_at)->toIso8601String()], 'Sale held.');
    }

    /** POST held-carts/{id}/take → the cart's state (it leaves the list: two tills can never both resume it). */
    public function take(Request $request, $id)
    {
        if ($off = $this->featureOff($request, 'held_carts')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'sell')) {
            return $deny;
        }
        try {
            return $this->success(['id' => (int) $id, 'state' => (new HeldCartService())->take($this->cid($request), (int) $id)], 'Held sale resumed.');
        } catch (BusinessRuleException $e) {
            return $this->rule($e);
        }
    }

    public function discard(Request $request, $id)
    {
        if ($off = $this->featureOff($request, 'held_carts')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'sell')) {
            return $deny;
        }
        (new HeldCartService())->discard($this->cid($request), (int) $id);

        return $this->success(null, 'Held sale discarded.');
    }

    // ── Short-dated stock (D2/B4: `fefo` or `markdowns`) ───

    /** GET expiring?days=&q= — batches on hand expiring within `days` (default: the shop's short_dated_days), soonest first. */
    public function expiring(Request $request)
    {
        if ($off = $this->featureOff($request, 'fefo', 'markdowns')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'adjust', 'restock')) {
            return $deny;
        }
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:0', 'max:365'], 'q' => ['nullable', 'string', 'max:100']]);
        $days = $data['days'] ?? ShortDatedService::defaultDays($this->company($request));
        $rows = (new ShortDatedService())->batches($this->cid($request), (int) $days, $data['q'] ?? null);
        $cost = $this->can($request, 'view_cost');
        $uuids = DB::table('stock_items')->whereIn('id', $rows->pluck('stock_item_id')->unique()->all())->pluck('uuid', 'id');

        return $this->success($rows->map(function ($r) use ($cost, $uuids) {
            $row = (array) $r + ['product_uuid' => $uuids[$r->stock_item_id] ?? null];
            if (! $cost) {
                unset($row['unit_cost'], $row['value']);
            }

            return $row;
        })->values(), 'Short-dated stock.', 200, ['days' => (int) $days]);
    }

    /** POST batches/{id}/markdown {pct} → the markdown (its MD barcode prints on the label). */
    public function markdown(Request $request, $id)
    {
        if ($off = $this->featureOff($request, 'markdowns')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'adjust')) {
            return $deny;
        }
        $data = $request->validate(['pct' => ['required', 'numeric', 'min:'.MarkdownService::MIN_PCT, 'max:'.MarkdownService::MAX_PCT]]);
        try {
            $m = (new MarkdownService())->create($this->cid($request), $this->uid($request), (int) $id, (float) $data['pct']);
        } catch (BusinessRuleException $e) {
            return $this->rule($e);
        }

        return $this->created((array) $m, 'Marked down '.rtrim(rtrim(number_format((float) $data['pct'], 2), '0'), '.').'%.');
    }

    /** POST batches/{id}/write-off {qty, reason: Expired|Damage|Lost|Internal Use, note?, approval_id?} — beyond the waste limit: 422 approval_required first. */
    public function writeOff(Request $request, $id)
    {
        if ($off = $this->featureOff($request, 'fefo', 'markdowns')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'adjust')) {
            return $deny;
        }
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:300'],
            'approval_id' => ['nullable', 'integer'],
        ]);
        $types = ['expired' => 'Expired', 'damage' => 'Damage', 'lost' => 'Lost', 'internal_use' => 'Internal Use'];
        $type = $types[strtolower(str_replace([' ', '-'], '_', (string) ($data['reason'] ?? 'expired')))] ?? null;
        if ($type === null) {
            return $this->error('Choose why the stock is written off: expired, damage, lost or internal_use.', 422, ['code' => 'invalid_movement_type']);
        }
        try {
            $r = (new ShortDatedService())->writeOff($this->cid($request), $this->uid($request), (int) $id, (float) $data['qty'], $type, $data['note'] ?? null, $data['approval_id'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->rule($e);
        }

        return $this->created(['movement_id' => (int) $r->id, 'movement_uuid' => $r->uuid, 'type' => $r->type, 'quantity' => (float) $r->quantity], 'Written off.');
    }

    // ── Shelf labels (B6, `shelf_labels`) ───────────────────

    public function labelQueue(Request $request)
    {
        if ($off = $this->featureOff($request, 'shelf_labels')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'manage_products', 'restock')) {
            return $deny;
        }
        $rows = (new ShelfLabelService())->pending($this->cid($request));
        $items = DB::table('stock_items')->whereIn('id', array_unique(array_column($rows, 'stock_item_id')))->get(['id', 'uuid', 'name', 'selling_price', 'barcode', 'sku'])->keyBy('id');

        return $this->success(array_map(fn ($r) => $r + ['product_uuid' => $items[$r['stock_item_id']]->uuid ?? null, 'name' => $items[$r['stock_item_id']]->name ?? null,
            'selling_price' => isset($items[$r['stock_item_id']]) ? (float) $items[$r['stock_item_id']]->selling_price : null,
            'barcode' => $items[$r['stock_item_id']]->barcode ?? null], $rows), 'Label queue.');
    }

    /** POST label-queue/printed {ids?: [..]} — no ids = every waiting label. */
    public function labelsPrinted(Request $request)
    {
        if ($off = $this->featureOff($request, 'shelf_labels')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'manage_products', 'restock')) {
            return $deny;
        }
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:1000'], 'ids.*' => ['integer']]);
        $n = (new ShelfLabelService())->markPrinted($this->cid($request), $data['ids'] ?? null);

        return $this->success(['printed' => $n], $n.' labels marked printed.');
    }

    // ── Tax classes (F1, `tax_classes`) ─────────────────────

    public function taxClasses(Request $request)
    {
        if ($off = $this->featureOff($request, 'tax_classes')) {
            return $off;
        }
        $company = $this->company($request);

        return $this->success($this->taxRows((int) $company->id, true), 'Tax classes.', 200, ['inclusive' => TaxClassService::inclusive($company), 'codes' => \App\Models\TaxClass::CODES]);
    }

    /** PUT tax-classes {classes: [{id?, name, code, rate, is_default?}], delete?: [ids]} — manage_settings. */
    public function saveTaxClasses(Request $request)
    {
        if ($off = $this->featureOff($request, 'tax_classes')) {
            return $off;
        }
        $data = $request->validate([
            'classes' => ['nullable', 'array', 'max:50'], 'classes.*.id' => ['nullable', 'integer'], 'classes.*.name' => ['required', 'string'],
            'classes.*.code' => ['required', 'string'], 'classes.*.rate' => ['required', 'numeric'], 'classes.*.is_default' => ['nullable', 'boolean'],
            'delete' => ['nullable', 'array'], 'delete.*' => ['integer'],
        ]);
        $cid = $this->cid($request);
        $svc = new TaxClassService();
        try {
            DB::transaction(function () use ($svc, $cid, $data) {
                $default = null;
                foreach ($data['classes'] ?? [] as $c) {
                    $class = $svc->save($cid, ['name' => $c['name'], 'code' => $c['code'], 'rate' => $c['rate']], isset($c['id']) ? (int) $c['id'] : null);
                    if (! empty($c['is_default'])) {
                        $default = (int) $class->id;
                    }
                }
                if ($default !== null) {
                    $svc->setDefault($cid, $default);
                }
                foreach ($data['delete'] ?? [] as $id) {
                    $svc->delete($cid, (int) $id);
                }
            });
        } catch (BusinessRuleException $e) {
            return $this->rule($e);
        }

        return $this->success($this->taxRows($cid, false), 'Tax classes saved.');
    }

    private function taxRows(int $cid, bool $ensure): array
    {
        $svc = new TaxClassService();
        $list = $ensure ? $svc->ensureDefaults($cid) : $svc->list($cid);

        return $list->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'code' => $c->code, 'rate' => (float) $c->rate, 'is_default' => (bool) $c->is_default,
            'label' => $c->name.' '.TaxClassService::pct((float) $c->rate)])->values()->all();
    }

    // ── Suppliers: price list (D7) and scorecard (D5) ───────

    /** GET suppliers/{id}/prices — the supplier's price in force today for each product it has one for. */
    public function supplierPrices(Request $request, $id)
    {
        if ($off = $this->featureOff($request, 'supplier_prices')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'restock', 'view_reports')) {
            return $deny;
        }
        $cid = $this->cid($request);
        $supplier = Supplier::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->find($id);
        if ($supplier === null) {
            return $this->notFound('Supplier not found.');
        }
        if (\App\Services\Sync\SyncRegistry::columns('supplier_prices') === []) {
            return $this->success([], 'Supplier prices.');
        }
        $ids = DB::table('supplier_prices')->where('company_id', $cid)->where('supplier_id', $supplier->id)->distinct()->pluck('stock_item_id')->all();
        $svc = new SupplierPriceService();
        $costs = $svc->currentMany($cid, (int) $supplier->id, $ids);
        $items = DB::table('stock_items')->whereIn('id', array_keys($costs))->where('is_deleted', 0)->get(['id', 'uuid', 'name', 'buying_price'])->keyBy('id');
        $rows = [];
        foreach ($costs as $itemId => $cost) {
            if (! isset($items[$itemId])) {
                continue;
            }
            $history = $svc->history($cid, (int) $supplier->id, (int) $itemId, 2);
            $rows[] = ['stock_item_id' => (int) $itemId, 'product_uuid' => $items[$itemId]->uuid, 'name' => $items[$itemId]->name, 'cost' => $cost,
                'since' => $history[0]->valid_from ?? null, 'previous' => isset($history[1]) ? (float) $history[1]->cost : null, 'buying_price' => (float) $items[$itemId]->buying_price];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->success($rows, 'Supplier prices.');
    }

    /**
     * GET stock-items/{id}/supplier-prices — what each supplier charges for this product, cheapest first
     * (SupplierPriceService::forProduct: cost in force today, since, previous, change_pct, source), each with its
     * price history, newest first.
     */
    public function productSupplierPrices(Request $request, $id)
    {
        if ($off = $this->featureOff($request, 'supplier_prices')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'restock', 'view_reports')) {
            return $deny;
        }
        $cid = $this->cid($request);
        $item = DB::table('stock_items')->where('company_id', $cid)->where('is_deleted', 0)->where('id', (int) $id)->first(['id', 'uuid', 'name', 'buying_price']);
        if ($item === null) {
            return $this->notFound('Stock item not found.');
        }
        $svc = new SupplierPriceService();
        $suppliers = array_map(fn ($row) => $row + ['history' => array_map(fn ($h) => ['cost' => (float) $h->cost, 'valid_from' => (string) $h->valid_from, 'source' => (string) $h->source],
            $svc->history($cid, (int) $row['supplier_id'], (int) $item->id))], $svc->forProduct($cid, (int) $item->id));

        return $this->success(['stock_item_id' => (int) $item->id, 'product_uuid' => $item->uuid, 'name' => $item->name, 'buying_price' => (float) $item->buying_price,
            'suppliers' => $suppliers], 'Supplier prices.');
    }

    /** GET suppliers/{id}/scorecard?days=90 */
    public function scorecard(Request $request, $id)
    {
        if ($off = $this->featureOff($request, 'smart_reorder')) {
            return $off;
        }
        if ($deny = $this->needsAny($request, 'restock', 'view_reports')) {
            return $deny;
        }
        $supplier = Supplier::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->find($id);
        if ($supplier === null) {
            return $this->notFound('Supplier not found.');
        }
        $days = max(7, min(365, (int) $request->query('days', 90)));

        return $this->success((new SmartReorderService())->scorecard($supplier, $days) + ['days' => $days], 'Supplier scorecard.');
    }

    // ── Drawer cash (E1, `cash_control`) ────────────────────

    /** POST cash-movements {type: drop|pickup|paid_in|paid_out|no_sale, amount, reason, shift_id, approval_id?, category_id?, client_uuid?} */
    public function cashMovement(Request $request)
    {
        if ($off = $this->featureOff($request, 'cash_control')) {
            return $off;
        }
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(\App\Models\CashMovement::TYPES))],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
            'shift_id' => ['required', 'integer'],
            'approval_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'client_uuid' => ['nullable', 'string', 'max:36'],
        ]);
        $shift = Shift::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($data['shift_id']);
        if ($shift === null) {
            return $this->notFound('Shift not found.');
        }
        try {
            $m = (new ShiftService())->cashMovement($shift, $data['type'], (float) ($data['amount'] ?? 0), $data['reason'], $this->uid($request),
                $data['approval_id'] ?? null, $data['client_uuid'] ?? null, $data['category_id'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->rule($e);
        }

        return $this->created(['id' => (int) $m->id, 'uuid' => $m->uuid, 'shift_id' => (int) $m->shift_id, 'shift_uuid' => $shift->uuid, 'type' => $m->type, 'amount' => (float) $m->amount,
            'reason' => $m->reason, 'approved_by' => $m->approved_by ? (int) $m->approved_by : null, 'server_seq' => (int) ($m->server_seq ?? 0),
            'expected_cash' => (float) ((new ShiftService())->totals($shift->fresh())['expected_cash'] ?? 0)], $m->label().' recorded.');
    }
}

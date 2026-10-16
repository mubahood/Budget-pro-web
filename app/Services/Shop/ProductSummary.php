<?php

namespace App\Services\Shop;

use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Support\LocalDate;
use App\Support\SalesSource;
use App\Support\StoreFeatures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One product's record for the phone (MOBILE_EXCELLENCE_PLAN.md D4), the same figures as the web
 * product peek (budget-pro-new Products\Peek): stock by location, the latest movements with a running
 * balance, sales over the last 30 days (SalesSource, net of returns), what each supplier charges
 * (SupplierPriceService, `supplier_prices`), price history (PriceBookService, `price_book`) and the
 * batches on the shelf. Costs only with view_cost, profit only with view_profit.
 */
class ProductSummary
{
    public const MOVEMENTS = 30;

    public const SALES_DAYS = 30;

    /** @return array<string, mixed> */
    public function for(StockItem $item, User $user): array
    {
        $cid = (int) $item->company_id;
        $company = Company::withoutGlobalScopes()->findOrFail($cid);
        $cost = Permissions::can($user, 'view_cost');
        $qty = round((float) $item->current_quantity, 3);
        $min = $item->min_stock !== null ? (float) $item->min_stock : (float) ($company->low_stock_default ?? config('saas.low_stock_threshold', 10));
        $unit = $item->unit_id ? DB::table('units')->where('id', $item->unit_id)->value('abbreviation') : null;

        return [
            'product' => ['id' => (int) $item->id, 'uuid' => $item->uuid, 'name' => (string) $item->name, 'sku' => $item->sku, 'barcode' => $item->barcode,
                'unit' => $unit ?: ($item->stockSubCategory?->measurement_unit ?? null), 'current_quantity' => $qty, 'reorder_level' => $min,
                'stock_state' => ! $item->track_stock ? 'in_stock' : ($qty < 0 ? 'oversold' : ($qty <= 0 ? 'out' : ($qty <= $min ? 'low' : 'in_stock'))),
                'selling_price' => round((float) $item->selling_price, 2), 'buying_price' => $cost ? round((float) $item->buying_price, 2) : null,
                'track_stock' => (bool) $item->track_stock, 'track_batches' => (bool) $item->track_batches, 'is_active' => (bool) $item->is_active],
            'locations' => $this->locations($cid, $item),
            'movements' => $this->movements($cid, $item, $cost),
            'sales_30d' => $this->sales($cid, (int) $item->id, Permissions::can($user, 'view_profit')),
            'supplier_prices' => StoreFeatures::enabled($company, 'supplier_prices') && (Permissions::can($user, 'restock') || Permissions::can($user, 'view_reports'))
                ? (new SupplierPriceService)->forProduct($cid, (int) $item->id) : null,
            'price_history' => StoreFeatures::enabled($company, 'price_book')
                ? ['applied' => (new PriceBookService)->history($cid, (int) $item->id, $cost ? ['selling', 'buying'] : ['selling'], 20),
                    'scheduled' => (new PriceBookService)->scheduled($cid, (int) $item->id, $cost ? ['selling', 'buying'] : ['selling'])] : null,
            'batches' => $this->batches($cid, $item),
        ];
    }

    /** @return list<array{location_id: ?int, name: string, is_default: bool, quantity: float}> */
    private function locations(int $cid, StockItem $item): array
    {
        $rows = DB::table('stock_levels as l')->join('locations as loc', 'loc.id', '=', 'l.location_id')->where('l.company_id', $cid)->where('l.stock_item_id', $item->id)
            ->orderByDesc('loc.is_default')->orderBy('loc.name')->get(['loc.id', 'loc.name', 'loc.is_default', 'l.quantity'])
            ->map(fn ($r) => ['location_id' => (int) $r->id, 'name' => (string) $r->name, 'is_default' => (bool) $r->is_default, 'quantity' => round((float) $r->quantity, 3)])->all();
        if ($rows !== []) {
            return $rows;
        }
        $main = DB::table('locations')->where('company_id', $cid)->orderByDesc('is_default')->orderBy('id')->first(['id', 'name']);

        return [['location_id' => $main ? (int) $main->id : null, 'name' => (string) ($main->name ?? 'Main shop'), 'is_default' => true, 'quantity' => round((float) $item->current_quantity, 3)]];
    }

    /** The latest movements, newest first; `balance` is the stock after each one (the newest ends at today's stock). */
    private function movements(int $cid, StockItem $item, bool $cost): array
    {
        $rows = DB::table('stock_records as r')->leftJoin('admin_users as u', 'u.id', '=', 'r.created_by_id')->leftJoin('sale_records as s', 's.id', '=', 'r.sale_record_id')
            ->where('r.company_id', $cid)->where('r.stock_item_id', $item->id)->where('r.is_deleted', 0)->orderByDesc('r.id')->limit(self::MOVEMENTS)
            ->get(['r.id', 'r.uuid', 'r.type', 'r.reason', 'r.quantity_delta', 'r.unit_cost', 'r.selling_price', 'r.created_at', 'r.sale_record_id', 'r.reference_type', 'r.reference_id',
                'r.is_reversal', 'r.reverses_id', 'r.location_id', 'u.name as who', 's.receipt_number']);
        $balance = round((float) $item->current_quantity, 3);

        return $rows->map(function ($r) use (&$balance, $cost) {
            $delta = round((float) ($r->quantity_delta ?? 0), 3);
            $row = ['id' => (int) $r->id, 'uuid' => $r->uuid, 'type' => (string) $r->type, 'reason' => $r->reason, 'delta' => $delta, 'balance' => $balance,
                'at' => $r->created_at ? Carbon::parse($r->created_at)->toIso8601String() : null, 'who' => $r->who, 'is_reversal' => (bool) $r->is_reversal,
                'unit_cost' => $cost && $r->unit_cost !== null ? (float) $r->unit_cost : null, 'location_id' => $r->location_id ? (int) $r->location_id : null,
                'document' => $r->sale_record_id ? ['type' => 'sale', 'id' => (int) $r->sale_record_id, 'label' => 'Sale '.($r->receipt_number ?: '#'.$r->sale_record_id)]
                    : ($r->reference_type ? ['type' => (string) $r->reference_type, 'id' => $r->reference_id ? (int) $r->reference_id : null, 'label' => null] : null)];
            $balance = round($balance - $delta, 3);

            return $row;
        })->all();
    }

    /** @return array{from: string, to: string, quantity: float, value: float, sales: int, profit: ?float} */
    private function sales(int $cid, int $itemId, bool $profit): array
    {
        \App\Support\LocalTime::prime($cid);
        $to = LocalDate::today($cid)->toDateString();
        $from = LocalDate::today($cid)->subDays(self::SALES_DAYS - 1)->toDateString();
        [$src, $bind] = SalesSource::linesSql($cid, 'l', $from, $to);
        $r = DB::selectOne("SELECT COALESCE(SUM(l.quantity), 0) AS qty, COALESCE(SUM(l.revenue), 0) AS revenue, COALESCE(SUM(l.profit), 0) AS profit, COUNT(DISTINCT l.sale_id) AS n
            FROM {$src} WHERE l.stock_item_id = ? AND l.sale_date BETWEEN ? AND ?", [...$bind, $itemId, $from, $to]);

        return ['from' => $from, 'to' => $to, 'quantity' => round((float) $r->qty, 3), 'value' => round((float) $r->revenue, 2), 'sales' => (int) $r->n,
            'profit' => $profit ? round((float) $r->profit, 2) : null];
    }

    /** Batches still on the shelf, soonest expiry first: `state` expired | soon (within 30 days) | ok | null (no date). */
    private function batches(int $cid, StockItem $item): array
    {
        if (! $item->track_batches) {
            return [];
        }
        $today = LocalDate::today($cid)->toDateString();
        $soon = LocalDate::today($cid)->addDays(30)->toDateString();

        return DB::table('stock_batches as b')->leftJoin('locations as loc', 'loc.id', '=', 'b.location_id')->where('b.company_id', $cid)->where('b.stock_item_id', $item->id)
            ->where('b.quantity', '>', 0)->orderByRaw('b.expiry_date IS NULL')->orderBy('b.expiry_date')->orderBy('b.id')->limit(100)
            ->get(['b.id', 'b.batch_number', 'b.expiry_date', 'b.quantity', 'loc.name as location'])
            ->map(function ($b) use ($today, $soon) {
                $expiry = $b->expiry_date ? substr((string) $b->expiry_date, 0, 10) : null;

                return ['id' => (int) $b->id, 'batch_number' => (string) $b->batch_number, 'expiry_date' => $expiry, 'location' => $b->location, 'quantity' => round((float) $b->quantity, 3),
                    'state' => $expiry === null ? null : ($expiry < $today ? 'expired' : ($expiry <= $soon ? 'soon' : 'ok'))];
            })->all();
    }
}

<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\StockRecord;
use App\Support\LocalDate;
use App\Support\StoreFeatures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Short-dated stock (SUPERMARKET_PLAN.md D2): batches that expire soon, with the value at risk, and
 * what a shop does about them — mark down (MarkdownService), return to the supplier (the existing
 * supplier return, prefilled from the delivery that brought the batch) or write off (an "Expired"
 * movement aimed at that batch, through ShrinkService so a big write-off still needs a supervisor).
 *
 * Sales already take batches First-Expiry-First-Out for every batch-tracked product (LocationStock),
 * so `fefo` changes nothing about selling; it switches on this screen and its daily alert.
 */
class ShortDatedService
{
    public const WINDOWS = [7, 14, 30];

    /** The screen and the alert are for shops with FEFO or markdowns on. */
    public static function enabled(?Company $company): bool
    {
        return StoreFeatures::enabled($company, 'fefo') || StoreFeatures::enabled($company, 'markdowns');
    }

    public static function defaultDays(?Company $company): int
    {
        return max(1, min(365, (int) StoreFeatures::setting($company, 'short_dated_days')));
    }

    /**
     * Batches on hand expiring within $days of the shop's today (already expired ones too), soonest first.
     *
     * @return Collection<int, object{id: int, stock_item_id: int, name: string, sku: ?string, batch_number: string, expiry_date: string, quantity: float,
     *                                unit_cost: float, value: float, selling_price: float, location: ?string, days_left: int, markdown_id: ?int, markdown_price: ?float, markdown_pct: ?float, markdown_barcode: ?string}>
     */
    public function batches(int $companyId, int $days, ?string $term = null, int $limit = 500): Collection
    {
        $today = LocalDate::today($companyId)->startOfDay();
        $until = $today->copy()->addDays(max(0, $days))->toDateString();
        $hasMarkdowns = \Illuminate\Support\Facades\Schema::hasTable('batch_markdowns');
        $q = DB::table('stock_batches as b')->join('stock_items as p', 'p.id', '=', 'b.stock_item_id')
            ->leftJoin('locations as l', 'l.id', '=', 'b.location_id')
            ->where('b.company_id', $companyId)->where('p.company_id', $companyId)->where('p.is_deleted', 0)
            ->where('b.quantity', '>', 0)->whereNotNull('b.expiry_date')->where('b.expiry_date', '<=', $until)
            ->when($term !== null && trim($term) !== '', fn ($w) => $w->where(fn ($x) => $x->where('p.name', 'like', '%'.trim($term).'%')->orWhere('b.batch_number', 'like', '%'.trim($term).'%')->orWhere('p.sku', trim($term))->orWhere('p.barcode', trim($term))))
            ->when($hasMarkdowns, fn ($w) => $w->leftJoin('batch_markdowns as m', fn ($j) => $j->on('m.stock_batch_id', '=', 'b.id')->where('m.status', '=', 'active')))
            ->orderBy('b.expiry_date')->orderBy('p.name')->limit($limit)
            ->select(array_merge(['b.id', 'b.stock_item_id', 'p.name', 'p.sku', 'b.batch_number', 'b.expiry_date', 'b.quantity', 'b.location_id', 'l.name as location',
                DB::raw('COALESCE(b.unit_cost, p.buying_price, 0) AS unit_cost'), 'p.selling_price'],
                $hasMarkdowns ? ['m.id as markdown_id', 'm.price as markdown_price', 'm.pct as markdown_pct', 'm.barcode as markdown_barcode'] : []));

        return $q->get()->map(function ($r) use ($today) {
            $r->id = (int) $r->id;
            $r->stock_item_id = (int) $r->stock_item_id;
            $r->quantity = (float) $r->quantity;
            $r->unit_cost = (float) $r->unit_cost;
            $r->selling_price = (float) $r->selling_price;
            $r->value = round($r->quantity * $r->unit_cost, 2);
            $r->days_left = (int) $today->diffInDays(Carbon::parse($r->expiry_date)->startOfDay(), false);
            $r->markdown_id = isset($r->markdown_id) ? (int) $r->markdown_id : null;
            $r->markdown_price = isset($r->markdown_price) ? (float) $r->markdown_price : null;
            $r->markdown_pct = isset($r->markdown_pct) ? (float) $r->markdown_pct : null;
            $r->markdown_barcode = $r->markdown_barcode ?? null;

            return $r;
        });
    }

    /** One batch of this shop that still has stock, or a refusal. */
    public static function batch(int $companyId, int $batchId): object
    {
        $b = DB::table('stock_batches as b')->join('stock_items as p', 'p.id', '=', 'b.stock_item_id')
            ->where('b.company_id', $companyId)->where('p.company_id', $companyId)->where('b.id', $batchId)->where('p.is_deleted', 0)
            ->first(['b.*', 'p.name', 'p.selling_price', 'p.buying_price']);
        if ($b === null) {
            throw BusinessRuleException::make('batch_not_found', 'That batch is not in this shop.');
        }

        return $b;
    }

    /**
     * Write a short-dated batch off: an "Expired" (or other write-off) movement taking this batch first.
     * Beyond the shop's waste limit it needs a supervisor (ShrinkService).
     */
    public function writeOff(int $companyId, int $userId, int $batchId, float $quantity, string $type = 'Expired', ?string $note = null, ?int $approvalId = null): StockRecord
    {
        $b = self::batch($companyId, $batchId);
        if (! ShrinkService::isWriteOff($type)) {
            throw BusinessRuleException::make('invalid_movement_type', 'Choose why the stock is written off.');
        }
        $quantity = round($quantity, 3);
        if ($quantity <= 0 || $quantity > round((float) $b->quantity, 3) + 0.0005) {
            throw BusinessRuleException::make('invalid_quantity', 'Write off between 0 and the '.rtrim(rtrim(number_format((float) $b->quantity, 3, '.', ''), '0'), '.').' in this batch.');
        }
        $reason = ['Expired' => 'expired', 'Damage' => 'damage', 'Lost' => 'lost', 'Internal Use' => 'internal_use'][$type];

        return (new ShrinkService())->record($companyId, $userId, [
            'stock_item_id' => (int) $b->stock_item_id, 'type' => $type, 'quantity' => $quantity, 'reason' => $reason,
            'unit_cost' => $b->unit_cost !== null ? (float) $b->unit_cost : null,
            'description' => trim('Batch '.$b->batch_number.' (expires '.$b->expiry_date.')'.($note ? ': '.$note : '')),
            'location_id' => $b->location_id ? (int) $b->location_id : null, 'batch_out' => (int) $b->id,
        ], $approvalId);
    }

    /** The delivery that brought this batch (for "return to supplier"): the latest one with a supplier. */
    public function sourceReceipt(int $companyId, int $batchId): ?int
    {
        $b = self::batch($companyId, $batchId);
        $q = fn (bool $sameBatch) => DB::table('goods_receipt_items as i')->join('goods_receipts as g', 'g.id', '=', 'i.goods_receipt_id')
            ->where('g.company_id', $companyId)->where('g.is_deleted', 0)->whereNotNull('g.supplier_id')->where('i.stock_item_id', $b->stock_item_id)
            ->when($sameBatch, fn ($w) => $w->where('i.batch_number', $b->batch_number))
            ->orderByDesc('g.id')->value('g.id');
        $id = $q(true) ?? $q(false);

        return $id ? (int) $id : null;
    }

    /** The daily alert's words, or null when nothing is short-dated. @return array{title: string, body: string, data: array<string, mixed>}|null */
    public function alert(Company $company): ?array
    {
        $days = self::defaultDays($company);
        $rows = $this->batches((int) $company->id, $days, null, 200);
        if ($rows->isEmpty()) {
            return null;
        }
        $value = round($rows->sum('value'), 2);
        $names = $rows->take(5)->map(fn ($r) => "{$r->name} {$r->batch_number} (".($r->days_left < 0 ? 'expired' : ($r->days_left === 0 ? 'today' : $r->days_left.'d')).')')->implode(', ');
        $more = $rows->count() > 5 ? ' and '.($rows->count() - 5).' more' : '';

        return ['title' => $rows->count().' batch'.($rows->count() > 1 ? 'es' : '')." expire within {$days} days",
            'body' => "Mark down, return or write off: {$names}{$more}. Value at cost: ".number_format($value).' '.($company->currency ?: '').'.',
            'data' => ['batch_ids' => $rows->pluck('id')->take(50)->all(), 'value' => $value, 'days' => $days]];
    }
}

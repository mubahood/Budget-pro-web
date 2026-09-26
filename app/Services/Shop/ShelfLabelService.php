<?php

namespace App\Services\Shop;

use App\Models\Company;
use App\Models\LabelQueueItem;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;

/**
 * Shelf-edge labels and the "Labels to print" queue (SUPERMARKET_PLAN.md B6, StoreFeatures
 * `shelf_labels`). A product joins the queue when its selling price changes (now or on schedule), when
 * it is new, when a promotion starts, or by hand; printing marks its labels printed. One waiting label
 * per product: a second reason does not add a second label.
 */
class ShelfLabelService
{
    public const REASONS = ['price_change', 'promotion', 'new', 'manual'];

    public const MAX = 500;

    /** Queue a label only when the shop has shelf labels on. */
    public static function queueIfOn(int $companyId, int $itemId, string $reason, ?int $unitId = null, ?Company $company = null): ?LabelQueueItem
    {
        $company ??= Company::withoutGlobalScopes()->find($companyId);

        return StoreFeatures::enabled($company, 'shelf_labels') ? self::queue($companyId, $itemId, $reason, $unitId) : null;
    }

    public static function queue(int $companyId, int $itemId, string $reason, ?int $unitId = null): LabelQueueItem
    {
        $reason = in_array($reason, self::REASONS, true) ? $reason : 'manual';
        $waiting = LabelQueueItem::query()->where('company_id', $companyId)->where('stock_item_id', $itemId)
            ->where(fn ($q) => $unitId === null ? $q->whereNull('unit_id') : $q->where('unit_id', $unitId))->whereNull('printed_at')->first();
        if ($waiting) {
            if ($waiting->reason !== $reason && $reason === 'price_change') {
                $waiting->forceFill(['reason' => $reason])->save(); // the price is the news
            }

            return $waiting;
        }

        return LabelQueueItem::create(['company_id' => $companyId, 'stock_item_id' => $itemId, 'unit_id' => $unitId, 'reason' => $reason, 'created_at' => now()]);
    }

    public function pendingCount(int $companyId): int
    {
        return (int) DB::table('label_queue as q')->join('stock_items as s', 's.id', '=', 'q.stock_item_id')
            ->where('q.company_id', $companyId)->whereNull('q.printed_at')->where('s.company_id', $companyId)->where('s.is_deleted', 0)->count();
    }

    /**
     * Waiting labels, oldest first.
     *
     * @return list<array{id: int, stock_item_id: int, unit_id: ?int, reason: string, at: string}>
     */
    public function pending(int $companyId, int $limit = self::MAX): array
    {
        return DB::table('label_queue as q')->join('stock_items as s', 's.id', '=', 'q.stock_item_id')
            ->where('q.company_id', $companyId)->whereNull('q.printed_at')->where('s.company_id', $companyId)->where('s.is_deleted', 0)
            ->orderBy('q.id')->limit($limit)->get(['q.id', 'q.stock_item_id', 'q.unit_id', 'q.reason', 'q.created_at'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'stock_item_id' => (int) $r->stock_item_id, 'unit_id' => $r->unit_id ? (int) $r->unit_id : null,
                'reason' => (string) $r->reason, 'at' => (string) $r->created_at])->all();
    }

    /**
     * Mark waiting labels printed: the given queue ids, or every waiting label of the shop.
     *
     * @param  list<int>|null  $ids
     */
    public function markPrinted(int $companyId, ?array $ids = null): int
    {
        return LabelQueueItem::query()->where('company_id', $companyId)->whereNull('printed_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->update(['printed_at' => now()]);
    }

    /** Take a waiting label off the queue without printing it. */
    public function remove(int $companyId, int $id): void
    {
        LabelQueueItem::query()->where('company_id', $companyId)->whereNull('printed_at')->whereKey($id)->delete();
    }

    /**
     * The comparison price shoppers see on the label: per kg / per L / per 100 g / per 100 ml / per m.
     * From how the product is sold (sold_by weight/volume/length: its price is already per kg, L or m),
     * its unit (kg, g, L, ml), or a pack size in its name ("Sugar 2kg", "Juice 500ml", "6 x 330ml").
     *
     * @return array{price: float, per: string}|null
     */
    public static function unitPrice(float $price, ?string $soldBy, ?string $unit, string $name): ?array
    {
        if ($price <= 0) {
            return null;
        }
        $unit = strtolower(trim((string) $unit));
        $soldBy = (string) $soldBy;
        if ($soldBy === 'weight' || in_array($unit, ['kg', 'kgs', 'kilo', 'kilogram'], true)) {
            return in_array($unit, ['g', 'gm', 'gram', 'grams'], true) ? ['price' => round($price * 100, 2), 'per' => '100 g'] : ['price' => round($price, 2), 'per' => 'kg'];
        }
        if ($soldBy === 'volume' || in_array($unit, ['l', 'ltr', 'litre', 'liter', 'lt'], true)) {
            return in_array($unit, ['ml'], true) ? ['price' => round($price * 100, 2), 'per' => '100 ml'] : ['price' => round($price, 2), 'per' => 'L'];
        }
        if ($soldBy === 'length' || in_array($unit, ['m', 'mtr', 'metre', 'meter'], true)) {
            return ['price' => round($price, 2), 'per' => 'm'];
        }
        // A pack size in the name: "2kg", "500 g", "1.5L", "6 x 330ml".
        if (! preg_match('/(?:(\d+)\s*[x×]\s*)?(\d+(?:[.,]\d+)?)\s*(kg|kgs|g|gm|gms|grams?|l|ltr|litres?|liters?|ml)\b/iu', $name, $m)) {
            return null;
        }
        $count = $m[1] !== '' ? max(1, (int) $m[1]) : 1;
        $size = (float) str_replace(',', '.', $m[2]) * $count;
        if ($size <= 0) {
            return null;
        }
        $u = strtolower($m[3]);
        $grams = in_array($u, ['kg', 'kgs'], true) ? $size * 1000 : (in_array($u, ['g', 'gm', 'gms', 'gram', 'grams'], true) ? $size : null);
        $ml = $grams === null ? (in_array($u, ['ml'], true) ? $size : $size * 1000) : null;
        if ($grams !== null) {
            return $grams >= 1000 ? ['price' => round($price / $grams * 1000, 2), 'per' => 'kg'] : ['price' => round($price / $grams * 100, 2), 'per' => '100 g'];
        }

        return $ml >= 1000 ? ['price' => round($price / $ml * 1000, 2), 'per' => 'L'] : ['price' => round($price / $ml * 100, 2), 'per' => '100 ml'];
    }
}

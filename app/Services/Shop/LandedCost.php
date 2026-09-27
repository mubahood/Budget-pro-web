<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Landed cost (SUPERMARKET_PLAN.md D7, the shop's `landed_cost` feature): transport, duty and handling paid
 * for a delivery are spread over its lines, by value (default) or by quantity. The landed unit cost is what
 * the stock movement is valued at and what the product's cost follows, so every later sale's profit (and the
 * stock value) includes them. The supplier's invoice (goods_receipts.total_cost, what is owed) is unchanged.
 */
class LandedCost
{
    public const SPLITS = ['value', 'quantity'];

    /**
     * The extra costs a receipt was given, cleaned: [{label, amount}] with amounts above zero.
     *
     * @return list<array{label: string, amount: float}>
     */
    public static function clean(mixed $costs): array
    {
        $out = [];
        foreach (is_array($costs) ? $costs : [] as $c) {
            $amount = round((float) ($c['amount'] ?? 0), 2);
            if ($amount < 0) {
                throw BusinessRuleException::make('invalid_landed_cost', 'An extra cost cannot be negative.');
            }
            if ($amount > 0) {
                $label = trim(mb_substr((string) ($c['label'] ?? ''), 0, 60));
                $out[] = ['label' => $label !== '' ? $label : 'Other costs', 'amount' => $amount];
            }
        }

        return $out;
    }

    /**
     * Share $extra over lines: per line key, the extra cost per unit.
     *
     * @param  array<int|string, array{quantity: float, cost: float}>  $lines
     * @return array<int|string, float>
     */
    public static function spread(array $lines, float $extra, string $by = 'value'): array
    {
        $qtyTotal = array_sum(array_map(fn ($l) => max(0.0, (float) $l['quantity']), $lines));
        $valueTotal = array_sum(array_map(fn ($l) => max(0.0, (float) $l['quantity'] * (float) $l['cost']), $lines));
        if ($by === 'value' && $valueTotal <= 0) {
            $by = 'quantity'; // free goods only: fall back to the quantities
        }
        $out = [];
        foreach ($lines as $key => $l) {
            $qty = (float) $l['quantity'];
            if ($qty <= 0) {
                $out[$key] = 0.0;

                continue;
            }
            $share = $by === 'value' ? ($qty * (float) $l['cost']) / $valueTotal : $qty / max($qtyTotal, 0.000001);
            $out[$key] = $extra * $share / $qty;
        }

        return $out;
    }

    /**
     * For GoodsReceiptService: the per-unit extra per line key, or null when the shop does not use landed
     * cost or the delivery has no extra costs.
     *
     * @return array{costs: list<array{label: string, amount: float}>, total: float, split: string, per_unit: array<int|string, float>}|null
     */
    public static function forReceipt(int $companyId, array $lines, array $options): ?array
    {
        $costs = self::clean($options['landed_costs'] ?? []);
        if ($costs === [] || ! Schema::hasColumn('goods_receipt_items', 'landed_unit_cost')
            || ! StoreFeatures::enabled(Company::withoutGlobalScopes()->find($companyId), 'landed_cost')) {
            return null;
        }
        $split = in_array($options['landed_split'] ?? 'value', self::SPLITS, true) ? (string) ($options['landed_split'] ?? 'value') : 'value';
        $prices = DB::table('stock_items')->where('company_id', $companyId)->whereIn('id', array_map(fn ($l) => (int) $l['stock_item_id'], $lines))->pluck('buying_price', 'id');
        $basis = [];
        foreach ($lines as $key => $l) {
            $raw = $l['unit_cost'] ?? null;
            $cost = ($raw === null || (is_string($raw) && trim($raw) === '')) ? (float) ($prices[(int) $l['stock_item_id']] ?? 0) : (float) $raw;
            $basis[$key] = ['quantity' => round((float) $l['quantity'], 3), 'cost' => round($cost, 2)];
        }
        $total = round(array_sum(array_column($costs, 'amount')), 2);

        return ['costs' => $costs, 'total' => $total, 'split' => $split, 'per_unit' => self::spread($basis, $total, $split)];
    }
}

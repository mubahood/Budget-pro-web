<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\Unit;
use App\Support\StoreFeatures;

/**
 * Price overrides at the till need a supervisor (supermarket plan A5), only with the `approvals`
 * feature on: a price cut beyond the shop's `override_limit_pct`, or a price below cost.
 *
 * The till asks for the approval (ApprovalService::grant, action `price_override`, amount = the
 * approved unit price) and sends its id on the line (`approval_id`); SaleService::checkout calls
 * enforce(), which refuses the sale without one and uses it up (ApprovalService::consume). Without the
 * feature nothing is checked.
 */
class PriceOverrideService
{
    /**
     * Does this price need a supervisor? null = no; else the reason, in shop words.
     *
     * @param  float  $catalogue  the product's price for this unit
     * @param  float  $price  the price per unit after the line's own discount
     * @param  float  $unitCost  the product's cost for this unit (0 = unknown)
     */
    public function reason(Company|int|null $company, float $catalogue, float $price, float $unitCost): ?string
    {
        $company = is_int($company) ? Company::withoutGlobalScopes()->find($company) : $company;
        if (! StoreFeatures::enabled($company, 'approvals') || $price >= $catalogue - 0.005) {
            return null;
        }
        if ($unitCost > 0 && $price < $unitCost - 0.005) {
            return 'below cost';
        }
        if ((new ApprovalService())->required($company, 'price_override', ['original' => $catalogue, 'price' => $price])) {
            return 'more than '.rtrim(rtrim(number_format((float) StoreFeatures::setting($company, 'override_limit_pct'), 2, '.', ''), '0'), '.').'% off';
        }

        return null;
    }

    /**
     * The checkout re-check: every line whose price needs a supervisor must carry an approval for at most
     * that price, given to this cashier; it is used up (inside the checkout's transaction, so a sale that
     * fails gives it back).
     *
     * @param  list<array<string, mixed>>  $items  checkout items (stock_item_id, quantity, unit_id?, unit_price?, discount_amount?, approval_id?)
     */
    public function enforce(int $companyId, int $userId, array $items): void
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        if (! StoreFeatures::enabled($company, 'approvals')) {
            return;
        }
        $departmentKeys = StoreFeatures::enabled($company, 'department_keys');
        foreach ($items as $line) {
            $hasPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== null;
            if (! $hasPrice && (float) ($line['discount_amount'] ?? 0) <= 0) {
                continue;
            }
            if ($hasPrice && ! empty($line['markdown_id']) && empty($line['unit_id']) && (float) ($line['discount_amount'] ?? 0) <= 0
                && ($md = MarkdownService::active($companyId, (int) $line['markdown_id'], (int) ($line['stock_item_id'] ?? 0)))
                && abs((float) $line['unit_price'] - $md['price']) < 0.005) {
                continue; // a markdown label's price was set by whoever marked the batch down
            }
            $product = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->find($line['stock_item_id'] ?? 0);
            if ($product === null || ($departmentKeys && $product->open_price)) {
                continue; // an open-price key is priced at the till
            }
            $factor = ! empty($line['unit_id']) ? max(0.001, (float) (Unit::withoutGlobalScopes()->where('company_id', $companyId)->find($line['unit_id'])?->factor ?: 1)) : 1.0;
            $catalogue = round((float) $product->selling_price * $factor, 2);
            $price = self::effective($hasPrice ? (float) $line['unit_price'] : $catalogue, (float) ($line['quantity'] ?? 0), (float) ($line['discount_amount'] ?? 0));
            $why = $this->reason($company, $catalogue, $price, round((float) $product->buying_price * $factor, 2));
            if ($why === null) {
                continue;
            }
            if (empty($line['approval_id'])) {
                throw BusinessRuleException::make('approval_required', "The price of {$product->name} is {$why}: a supervisor must approve it with their PIN.", ['stock_item_id' => $product->id]);
            }
            $approval = (new ApprovalService())->consume($companyId, (int) $line['approval_id'], 'price_override', $userId);
            if ($approval->amount !== null && $price < (float) $approval->amount - 0.005) {
                throw BusinessRuleException::make('approval_invalid', "The price of {$product->name} is lower than the supervisor approved. Ask again.", ['stock_item_id' => $product->id]);
            }
        }
    }

    /** The price per unit after a line discount. */
    public static function effective(float $unitPrice, float $qty, float $lineDiscount): float
    {
        if ($qty <= 0 || $lineDiscount <= 0) {
            return round($unitPrice, 2);
        }
        $sub = round($qty * $unitPrice, 2);

        return round(max(0, $sub - min($lineDiscount, $sub)) / $qty, 2);
    }
}

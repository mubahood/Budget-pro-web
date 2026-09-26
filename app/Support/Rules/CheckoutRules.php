<?php

namespace App\Support\Rules;

use App\Models\StockItem;
use App\Models\Unit;
use Illuminate\Validation\Rule;

/**
 * What a checkout (a sale made at a till) may contain: one rule set for the mobile API
 * (Api\V1\SaleController::checkout) and the new web POS (budget-pro-new), so the two validate
 * a basket identically and refuse the same price changes.
 */
class CheckoutRules
{
    /** The refusal when a role without `discount` gives a discount or changes a price. */
    public const PRICE_REFUSAL = 'Your role cannot give discounts or change prices.';

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId): array
    {
        return [
            'client_uuid' => ['nullable', 'uuid'],
            'customer_name' => ['nullable', 'string', 'max:191'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:191'],
            'sale_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'customer_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['nullable', 'string', 'max:30'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'payments.*.reference' => ['nullable', 'string', 'max:191'],
            'payments.*.provider' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** A discount, or a unit price different from the product's price in that unit (needs the `discount` permission). */
    public static function changesPrices(int $companyId, array $data): bool
    {
        if ((float) ($data['discount_amount'] ?? 0) > 0) {
            return true;
        }
        foreach ($data['items'] ?? [] as $line) {
            if ((float) ($line['discount_amount'] ?? 0) > 0) {
                return true;
            }
            if (! isset($line['unit_price'])) {
                continue;
            }
            $product = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->find($line['stock_item_id']);
            $factor = ! empty($line['unit_id']) ? (float) (Unit::withoutGlobalScopes()->find($line['unit_id'])?->factor ?: 1) : 1.0;
            if ($product && abs((float) $line['unit_price'] - round((float) $product->selling_price * $factor, 2)) > 0.005) {
                return true;
            }
        }

        return false;
    }
}

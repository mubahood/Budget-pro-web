<?php

namespace App\Support\Rules;

use App\Services\Shop\StockService;
use Illuminate\Validation\Rule;

/**
 * What a stock movement may contain: one rule set for the mobile API, the classic movement form
 * and the new web interface (budget-pro-new).
 */
class StockRecordRules
{
    /** Fields a person may send (everything else is derived by the StockRecord hooks). */
    public const WRITABLE = ['stock_item_id', 'type', 'quantity', 'description', 'date', 'selling_price', 'unit_cost', 'client_uuid', 'reason', 'image'];

    /** Reason codes for adjustments (plan A4). */
    public const REASONS = ['damage', 'expired', 'lost', 'theft', 'internal_use', 'correction', 'gift', 'restock', 'return', 'other'];

    /**
     * Movement types a person may record by hand on the web. Sales are made on Sales / POS so they get
     * a receipt and payment; deliveries on Receive stock so the cost and what is owed are recorded.
     */
    public const FORM_TYPES = [
        'Stock In' => 'Stock in (goods came in without a delivery note)',
        'Adjustment In' => 'Count correction + (found more than recorded)',
        'Adjustment Out' => 'Count correction − (found less than recorded)',
        'Damage' => 'Damaged (write-off)',
        'Expired' => 'Expired (disposal)',
        'Lost' => 'Lost / stolen',
        'Internal Use' => 'Used in the business',
        'Return' => 'Customer return without a receipt (stock in)',
        'Other' => 'Other (stock out)',
    ];

    /** Types that record what the stock cost (inbound goods); a blank cost means the product's buying price. */
    public const COSTED_TYPES = ['Stock In', 'Adjustment In'];

    /** The permission a movement type needs: inbound = restock, outbound = adjust, a sale = sell. */
    public static function permissionFor(string $type): string
    {
        return $type === 'Sale' ? 'sell' : (StockService::isInbound($type) ? 'restock' : 'adjust');
    }

    public static function reasonLabel(?string $reason): string
    {
        return $reason ? ucfirst(str_replace('_', ' ', $reason)) : '';
    }

    /**
     * @param  bool  $formOnly  true = only FORM_TYPES (a person on the web), false = every type (the API/sync)
     * @return array<string, array<int, mixed>>
     */
    public static function rules(int $companyId, bool $formOnly = false): array
    {
        return [
            'client_uuid' => ['nullable', 'uuid'],
            'stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'type' => ['required', Rule::in($formOnly ? array_keys(self::FORM_TYPES) : StockService::types())],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'description' => ['nullable', 'string', 'max:1000'],
            'date' => ['nullable', 'date'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'in:'.implode(',', self::REASONS)],
            'image' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function reverseRules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}

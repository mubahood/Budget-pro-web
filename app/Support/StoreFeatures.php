<?php

namespace App\Support;

use App\Models\Company;

/**
 * Supermarket features, per shop (docs: budget-pro-new/docs/SUPERMARKET_PLAN.md).
 *
 * Every feature is OFF unless the shop turns on Supermarket mode (all of them, with defaults) or the
 * feature itself. A shop that never touches this sees Budget Pro exactly as before. Settings that go
 * with the features (scale barcode format, cash rounding, approval limits, loyalty rate…) live in the
 * same JSON column, `companies.store_settings`:
 *   {"mode": true, "features": {"promotions": false}, "settings": {"cash_rounding": 50}}
 */
class StoreFeatures
{
    /** key => [label, plan item] */
    public const FEATURES = [
        'pack_barcodes' => ['Barcodes per pack (can, 6-pack, carton)', 'A1'],
        'weighed_items' => ['Weighed items: scale barcodes, PLU codes, price per kg', 'A2'],
        'department_keys' => ['Open-price department keys', 'A4'],
        'approvals' => ['Supervisor PIN for voids, refunds and overrides', 'A5'],
        'held_carts' => ['Held carts kept on the server', 'A6'],
        'fast_tender' => ['Fast tender: note buttons and cash rounding', 'A7'],
        'customer_display' => ['Customer-facing display', 'A8'],
        'age_check' => ['Age check for restricted items', 'A10'],
        'deposits' => ['Container deposits (crates, bottles)', 'A11'],
        'offline_till' => ['Web till keeps selling offline', 'A12'],
        'price_book' => ['Price history and scheduled price changes', 'B1'],
        'price_levels' => ['Price levels and quantity breaks', 'B2'],
        'promotions' => ['Promotions', 'B3'],
        'markdowns' => ['Markdowns for short-dated stock', 'B4'],
        'shelf_labels' => ['Shelf-edge labels and the label queue', 'B6'],
        'loyalty' => ['Loyalty points', 'C1'],
        'gift_cards' => ['Gift cards and store credit', 'C2'],
        'scan_receiving' => ['Receive deliveries by scanning', 'D1'],
        'fefo' => ['Sell the first-to-expire batch first', 'D2'],
        'aisle_counts' => ['Shelf locations and aisle counts', 'D3'],
        'break_packs' => ['Open cartons automatically', 'D6'],
        'cash_control' => ['Cash drops, paid-in/out, X and Z reports', 'E1'],
        'blind_cashup' => ['Blind cash-up with note counting', 'E3'],
        'tax_classes' => ['Tax classes per product', 'F1'],
        'fiscal' => ['Fiscal receipts / e-invoicing', 'F2'],
    ];

    /** Setting => default. */
    public const SETTINGS = [
        'cash_rounding' => 0,              // 0 = none; else round cash totals to this step (e.g. 50 UGX, 0.05 USD)
        'note_buttons' => [],              // [] = from the currency (CURRENCY_NOTES)
        'scale_prefixes' => ['20', '21', '22', '23', '24', '25', '26', '27', '28', '29'],
        'scale_format' => 'price',         // 'price' | 'weight': what the 5 value digits of an EAN-13 scale label carry
        'scale_item_digits' => 5,          // digits after the 2-digit prefix that identify the item (PLU)
        'scale_value_decimals' => 0,       // decimals in the value (weight: 3 = grams; price: currency decimals)
        'override_limit_pct' => 10,        // price cut above this % needs a supervisor
        'waste_limit' => 0,                // write-off value above this needs a supervisor (0 = always)
        'loyalty_spend_per_point' => 1000, // currency per point earned
        'loyalty_point_value' => 10,       // currency one point is worth when redeemed
        'short_dated_days' => 14,
        'age_min' => 18,
        'tax_inclusive' => true,
    ];

    /** Common note denominations for the fast-tender buttons (smallest useful first). */
    public const CURRENCY_NOTES = [
        'UGX' => [1000, 2000, 5000, 10000, 20000, 50000], 'KES' => [50, 100, 200, 500, 1000], 'TZS' => [1000, 2000, 5000, 10000],
        'RWF' => [500, 1000, 2000, 5000], 'USD' => [1, 5, 10, 20, 50, 100], 'EUR' => [5, 10, 20, 50, 100], 'GBP' => [5, 10, 20, 50],
        'NGN' => [200, 500, 1000], 'GHS' => [5, 10, 20, 50, 100, 200], 'ZAR' => [10, 20, 50, 100, 200], 'INR' => [50, 100, 200, 500],
    ];

    private static function data(?Company $company): array
    {
        $raw = $company?->getAttribute('store_settings');
        $data = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);

        return $data;
    }

    /** Supermarket mode: on when set, or by default for a shop set up as a supermarket. */
    public static function mode(?Company $company): bool
    {
        $data = self::data($company);

        return array_key_exists('mode', $data) ? (bool) $data['mode'] : ($company?->business_type === 'supermarket');
    }

    public static function enabled(?Company $company, string $feature): bool
    {
        if ($company === null || ! array_key_exists($feature, self::FEATURES)) {
            return false;
        }
        $own = self::data($company)['features'][$feature] ?? null;

        return $own !== null ? (bool) $own : self::mode($company);
    }

    public static function setting(?Company $company, string $key): mixed
    {
        $value = self::data($company)['settings'][$key] ?? null;
        if ($value !== null) {
            return $value;
        }
        if ($key === 'note_buttons') {
            return self::CURRENCY_NOTES[strtoupper((string) $company?->currency)] ?? [];
        }

        return self::SETTINGS[$key] ?? null;
    }

    /** @return array<string, bool> every feature and whether it is on for this shop */
    public static function all(?Company $company): array
    {
        return collect(self::FEATURES)->mapWithKeys(fn ($f, $key) => [$key => self::enabled($company, $key)])->all();
    }

    /**
     * Validation rules for a change passed to update(): {mode?, features?: {key: bool|null}, settings?: {key: value}}.
     * Every screen and API that edits these settings validates with them.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'mode' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['nullable', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.cash_rounding' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'settings.note_buttons' => ['nullable', 'array', 'max:12'],
            'settings.note_buttons.*' => ['numeric', 'gt:0', 'max:100000000'],
            'settings.scale_prefixes' => ['nullable', 'array', 'max:10'],
            'settings.scale_prefixes.*' => ['string', 'regex:/^\d{2}$/'],
            'settings.scale_format' => ['nullable', 'in:price,weight'],
            'settings.scale_item_digits' => ['nullable', 'integer', 'min:4', 'max:5'],
            'settings.scale_value_decimals' => ['nullable', 'integer', 'min:0', 'max:3'],
            'settings.override_limit_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.waste_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'settings.loyalty_spend_per_point' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'settings.loyalty_point_value' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'settings.short_dated_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'settings.age_min' => ['nullable', 'integer', 'min:0', 'max:99'],
            'settings.tax_inclusive' => ['nullable', 'boolean'],
        ];
    }

    /**
     * A change checked against rules() (ValidationException on a bad value) and typed like the defaults
     * (whole numbers stay integers, "0.05" becomes 0.05, lists become lists of numbers / strings), ready for update().
     */
    public static function validated(array $change): array
    {
        $data = \Illuminate\Support\Facades\Validator::make($change, self::rules(), [], [
            'settings.cash_rounding' => 'cash rounding', 'settings.note_buttons.*' => 'note', 'settings.scale_prefixes.*' => 'scale prefix',
            'settings.override_limit_pct' => 'price-cut limit', 'settings.waste_limit' => 'write-off limit', 'settings.short_dated_days' => 'short-dated days',
            'settings.loyalty_spend_per_point' => 'spend per point', 'settings.loyalty_point_value' => 'point value', 'settings.age_min' => 'minimum age',
        ])->validate();
        $num = fn ($v) => (float) $v == (int) $v ? (int) $v : (float) $v;
        foreach ((array) ($data['settings'] ?? []) as $key => $value) {
            if ($value === null || ! array_key_exists($key, self::SETTINGS)) {
                continue;
            }
            $default = self::SETTINGS[$key];
            $data['settings'][$key] = match (true) {
                $key === 'note_buttons' => array_values(array_map($num, (array) $value)),
                $key === 'scale_prefixes' => array_values(array_map('strval', (array) $value)),
                is_bool($default) => (bool) $value,
                is_int($default) || is_float($default) => $num($value),
                default => (string) $value,
            };
        }

        return $data;
    }

    /** Merge a change (mode / features / settings) into the shop's store_settings and save it. */
    public static function update(Company $company, array $change): Company
    {
        $data = self::data($company);
        if (array_key_exists('mode', $change)) {
            $data['mode'] = (bool) $change['mode'];
        }
        foreach ((array) ($change['features'] ?? []) as $key => $on) {
            if (array_key_exists($key, self::FEATURES)) {
                $data['features'][$key] = $on === null ? null : (bool) $on;
            }
        }
        $data['features'] = array_filter((array) ($data['features'] ?? []), fn ($v) => $v !== null);
        foreach ((array) ($change['settings'] ?? []) as $key => $value) {
            if (array_key_exists($key, self::SETTINGS)) {
                $data['settings'][$key] = $value;
            }
        }
        $company->forceFill(['store_settings' => $data])->save();
        if (self::enabled($company, 'pack_barcodes')) {
            \App\Services\Shop\BarcodeService::backfillPrimary((int) $company->id); // idempotent: products' own barcodes join the lookup
        }

        return $company;
    }
}

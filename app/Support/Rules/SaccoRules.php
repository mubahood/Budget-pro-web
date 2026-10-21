<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * Field rules shared by every Akiba Ledger form and endpoint (AK-52): money only as `*_minor` integers, rates as
 * whole basis points. A decimal or a float is refused with 422, so no client can bring a float into the books.
 */
final class SaccoRules
{
    /**
     * Whether the SACCO is regulated: it has a licence number (P1.7, AK-14). Checks that rest on a law (the
     * Cooperative Societies Act and its regulations, the Tier 4 Act and SI 51, the DPPA) apply only then; every
     * other SACCO or group may proceed (AK-69, the owner's instruction of 2026-10-21).
     */
    public static function strict(\App\Models\Company|int|null $company): bool
    {
        $id = $company instanceof \App\Models\Company ? (int) $company->id : $company;

        return $id !== null && filled(\App\Models\SaccoSetting::for($id)?->licence_no);
    }

    /** The largest amount a form may carry (well inside BIGINT, so sums of many still fit). */
    public const MAX_MINOR = 1_000_000_000_000_000;

    /** @return array<int, mixed> */
    public static function minor(bool $required = true, int $min = 0): array
    {
        return [$required ? 'required' : 'sometimes', 'integer', 'min:'.$min, 'max:'.self::MAX_MINOR, self::noFloat()];
    }

    /** @return array<int, mixed> */
    public static function bp(int $max = 1000000, bool $required = false): array
    {
        return [$required ? 'required' : 'sometimes', 'integer', 'min:0', 'max:'.$max, self::noFloat()];
    }

    /** A JSON number with a decimal point (1.0, 2.5) is not whole minor units, even when Laravel's integer rule lets 1.0 through. */
    public static function noFloat(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (is_float($value) || (is_string($value) && str_contains($value, '.'))) {
                $fail('Send money as whole minor units (an integer), never a decimal.');
            }
        };
    }

    /** @return array<string, array<int, mixed>> the settings a SACCO may change (SaccoSettingsService) */
    public static function settings(): array
    {
        return [
            'legal_name' => ['sometimes', 'required', 'string', 'max:190'],
            'short_name' => ['sometimes', 'required', 'string', 'max:60'],
            'registration_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'licence_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'licence_expires_on' => ['sometimes', 'nullable', 'date'],
            'regulator_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'regulator_short' => ['sometimes', 'nullable', 'string', 'max:30'],
            'regulator_portal_url' => ['sometimes', 'nullable', 'url', 'max:190'],
            'show_regulated_line' => ['sometimes', 'boolean'],
            'fiscal_year_start_month' => ['sometimes', 'integer', 'between:1,12'],
            'share_par_minor' => self::minor(false, 1),
            'min_shares' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'max_holding_bp' => self::bp(3333),
            'entrance_fee_minor' => self::minor(false),
            'withdrawal_approval_above_minor' => self::minor(false),
            'offline_receipt_max_minor' => self::minor(false),
            'single_officer_mode' => ['sometimes', 'boolean'],
            'receipts_channel' => ['sometimes', Rule::in(\App\Models\SaccoSetting::RECEIPT_CHANNELS)],
            'dividend_cap_bp' => self::bp(1000),
            'reserve_min_bp' => ['sometimes', 'integer', 'min:1000', 'max:10000', self::noFloat()],
            'education_fund_bp' => self::bp(10000),
            'dormancy_months' => ['sometimes', 'integer', 'between:12,60'],
            'employee_loans_policy' => ['sometimes', Rule::in(\App\Models\SaccoSetting::EMPLOYEE_LOAN_POLICIES)],
            'allocation_order' => ['sometimes', 'string', 'regex:/^(penalty|fees|interest|principal)(,(penalty|fees|interest|principal)){3}$/'],
        ];
    }

    /** @return array<string, array<int, mixed>> the first-run setup (SaccoSetupService::setup) */
    public static function setup(): array
    {
        return ['mode' => ['required', Rule::in(\App\Models\SaccoSetting::MODES)], 'founder_position' => ['nullable', Rule::in(['chairperson', 'treasurer'])]] + array_merge(self::settings(), [
            'legal_name' => ['required', 'string', 'max:190'],
            'short_name' => ['nullable', 'string', 'max:60'],
        ]);
    }
}

<?php

namespace App\Support\Rules;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What the owner may change about their business: one rule set for the mobile API
 * (PUT company, PUT company/engagement) and the new web interface (budget-pro-new Settings),
 * so the two can never disagree about a timezone, a VAT rate or when the currency is locked.
 *
 * Every rule starts with `sometimes`: a request changes only the fields it sends.
 */
class CompanyRules
{
    /** The settings screen's sections and the columns each one writes. */
    public const SECTIONS = [
        'profile' => ['name', 'phone_number', 'email', 'address', 'logo'],
        'money' => ['currency', 'timezone', 'tax_rate'],
        'receipts' => ['receipt_header', 'receipt_footer', 'receipt_channels', 'payment_methods'],
        'stock' => ['low_stock_default', 'negative_stock_policy', 'require_shift'],
        'customers' => ['credit_terms_days', 'debt_reminders_enabled'],
        'modules' => ['enabled_modules'],
    ];

    /** Older profile fields the phone app still sends (kept writable for it). */
    public const LEGACY = [
        'phone_number_2', 'website', 'about', 'slogan',
        'settings_worker_can_create_stock_item', 'settings_worker_can_create_stock_record', 'settings_worker_can_create_stock_category',
        'settings_worker_can_view_balance', 'settings_worker_can_view_stats',
    ];

    /** How receipts can reach a customer. */
    public const RECEIPT_CHANNELS = ['whatsapp' => 'WhatsApp', 'print' => 'Printed', 'sms' => 'SMS'];

    /** What happens when a sale asks for more than the shelf has, in plain words. */
    public const NEGATIVE_STOCK = [
        'flag' => ['Allow it, but warn me', 'The sale goes through and the product is flagged so you can count it. Best when stock records are not perfect yet.'],
        'allow' => ['Allow it silently', 'The sale goes through and stock goes below zero without a warning.'],
        'block' => ['Block the sale', 'The till refuses to sell more than is in stock (checked online). Best once your counts are right.'],
    ];

    /** @return array<int, string> */
    public static function writable(): array
    {
        return array_merge(array_merge(...array_values(self::SECTIONS)), self::LEGACY);
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(Company $company): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'phone_number_2' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'website' => ['sometimes', 'nullable', 'string', 'max:191'],
            'about' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'slogan' => ['sometimes', 'nullable', 'string', 'max:255'],
            'logo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'required', 'string', Rule::in(config('saas.currencies')), function (string $attribute, mixed $value, \Closure $fail) use ($company) {
                if (strtoupper((string) $value) !== strtoupper((string) $company->currency) && self::currencyLocked($company)) {
                    $fail('The currency cannot change once the shop has sales: every past figure is in '.$company->currency.'.');
                }
            }],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:50'],
            'receipt_header' => ['sometimes', 'nullable', 'string', 'max:500'],
            'receipt_footer' => ['sometimes', 'nullable', 'string', 'max:500'],
            'receipt_channels' => ['sometimes', 'array'],
            'receipt_channels.*' => [Rule::in(array_keys(self::RECEIPT_CHANNELS))],
            'payment_methods' => ['sometimes', 'array', 'min:1'],
            'payment_methods.*' => [Rule::in(array_keys(config('onboarding.payment_methods')))],
            'low_stock_default' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'negative_stock_policy' => ['sometimes', 'required', Rule::in(array_keys(self::NEGATIVE_STOCK))],
            'require_shift' => ['sometimes', 'boolean'],
            'credit_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'debt_reminders_enabled' => ['sometimes', 'boolean'],
            'enabled_modules' => ['sometimes', 'array', 'min:1'],
            'enabled_modules.*' => [Rule::in(array_keys(config('onboarding.modules')))],
            'settings_worker_can_create_stock_item' => ['sometimes', 'nullable', Rule::in(['Yes', 'No'])],
            'settings_worker_can_create_stock_record' => ['sometimes', 'nullable', Rule::in(['Yes', 'No'])],
            'settings_worker_can_create_stock_category' => ['sometimes', 'nullable', Rule::in(['Yes', 'No'])],
            'settings_worker_can_view_balance' => ['sometimes', 'nullable', Rule::in(['Yes', 'No'])],
            'settings_worker_can_view_stats' => ['sometimes', 'nullable', Rule::in(['Yes', 'No'])],
        ];
    }

    /**
     * The rules for some fields only (e.g. one settings section), with their `field.*` companions.
     *
     * @param  array<int, string>  $fields
     * @return array<string, array<int, mixed>>
     */
    public static function only(Company $company, array $fields): array
    {
        return array_filter(self::rules($company), fn ($key) => in_array(explode('.', $key)[0], $fields, true), ARRAY_FILTER_USE_KEY);
    }

    /** Any sale recorded yet (web/app sale documents or the old app's sale movements)? */
    public static function hasSales(int $companyId): bool
    {
        return DB::table('sale_records')->where('company_id', $companyId)->exists()
            || DB::table('stock_records')->where('company_id', $companyId)->where('type', 'Sale')->exists();
    }

    /** Past figures are stored in the shop's currency, so it is fixed once there is a sale. */
    public static function currencyLocked(Company $company): bool
    {
        return $company->exists && $company->currency && self::hasSales((int) $company->id);
    }

    /** The payment method keys the shop takes (payment_methods is stored as {methods, momo, opening_float}). */
    public static function paymentMethods(Company $company): array
    {
        $pm = $company->payment_methods;

        return is_array($pm) && isset($pm['methods']) ? array_values((array) $pm['methods']) : [];
    }

    /**
     * Put validated values on the company, in the shapes the columns hold. The caller saves.
     *
     * @param  array<string, mixed>  $data  output of validate(rules())
     */
    public static function apply(Company $company, array $data): Company
    {
        foreach (array_intersect_key($data, array_flip(self::writable())) as $key => $value) {
            $company->{$key} = match ($key) {
                'currency' => strtoupper((string) $value),
                'payment_methods' => array_merge(['momo' => [], 'opening_float' => 0.0], is_array($company->payment_methods) ? $company->payment_methods : [],
                    ['methods' => array_values(array_unique($value))]),
                'receipt_channels', 'enabled_modules' => array_values(array_unique($value)),
                'require_shift', 'debt_reminders_enabled' => (bool) $value,
                'credit_terms_days' => (int) $value,
                'tax_rate', 'low_stock_default' => $value === null || $value === '' ? null : (float) $value,
                default => $value,
            };
        }

        return $company;
    }
}

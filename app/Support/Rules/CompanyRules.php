<?php

namespace App\Support\Rules;

use App\Models\Company;
use App\Support\BrandColour;
use App\Support\Edition;
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
        'profile' => ['name', 'phone_number', 'phone_number_2', 'email', 'address', 'slogan', 'logo'],
        'money' => ['currency', 'timezone', 'tax_rate'],
        'receipts' => ['receipt_header', 'receipt_footer', 'receipt_channels', 'payment_methods'],
        'stock' => ['low_stock_default', 'negative_stock_policy', 'require_shift'],
        'customers' => ['credit_terms_days', 'debt_reminders_enabled'],
        'modules' => ['enabled_modules'],
    ];

    /** The buyer's brand (STANDALONE_EDITION_PLAN.md §15): part of Business profile on a standalone copy only. */
    public const BRAND = ['color', 'logo_wide'];

    /**
     * The modules a business is built around (budget-pro-new docs/FAMILY_PLAN.md): every account keeps at
     * least one of them, so it always has a home screen. `finance` (Income & expenses) sits beside them.
     */
    public const CORE_MODULES = ['shop', 'poultry', 'budget'];

    public const CORE_MODULE_MESSAGE = 'Pick at least one: Shop, Poultry farm or Budget & pledges.';

    /** Older profile fields the phone app still sends (kept writable for it; phone_number_2 and slogan are also in 'profile'). */
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
        return array_merge(array_merge(...array_values(self::SECTIONS)), self::LEGACY, self::BRAND);
    }

    /** The columns a settings section writes in this edition (Business profile carries the brand on standalone). */
    public static function sectionFields(string $section): array
    {
        $fields = self::SECTIONS[$section] ?? [];

        return $section === 'profile' && Edition::isStandalone() ? array_merge($fields, self::BRAND) : $fields;
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
            'logo_wide' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Printed into a <style> tag and under white button text: #RRGGBB only, and dark enough (4.5:1).
            'color' => ['sometimes', 'nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value === null || trim((string) $value) === '') {
                    return;
                }
                $hex = BrandColour::normalise((string) $value);
                if ($hex === null) {
                    $fail('Enter the colour as a hex code, like #0F7B4F.');
                } elseif (! BrandColour::readableOnWhite($hex)) {
                    $fail('This colour is too light: white text on it would be hard to read ('.number_format(BrandColour::contrastWithWhite($hex), 1).':1, it needs 4.5:1). Choose a darker shade.');
                }
            }],
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
            'enabled_modules' => ['sometimes', 'array', 'min:1', self::coreModuleRule()],
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

    /** A list of modules must hold at least one core module (shop, poultry or budget). */
    public static function coreModuleRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (is_array($value) && $value !== [] && array_intersect($value, self::CORE_MODULES) === []) {
                $fail(self::CORE_MODULE_MESSAGE);
            }
        };
    }

    /**
     * The rules for a list of modules sent under another name (PUT company/modules, sign-up's `modules`).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function modulesRules(string $key = 'modules', bool $required = true): array
    {
        return [
            $key => $required ? ['required', 'array', 'min:1', self::coreModuleRule()] : ['nullable', 'array', self::coreModuleRule()],
            $key.'.*' => ['string', Rule::in(array_keys(config('onboarding.modules')))],
        ];
    }

    /**
     * Modules picked at sign-up, as stored: the known core modules in their usual order, then Income &
     * expenses, which every new account gets. Null when none was picked (the business type decides).
     *
     * @param  array<int, mixed>|null  $picked
     * @return list<string>|null
     */
    public static function signUpModules(?array $picked): ?array
    {
        $core = array_values(array_intersect(self::CORE_MODULES, array_map('strval', (array) $picked)));

        return $core === [] ? null : [...$core, 'finance'];
    }

    /**
     * The business type that goes with modules picked at sign-up: the shop's own type when Shop is
     * picked, otherwise `poultry` (Poultry farm picked) or `fundraising` (Budget & pledges only).
     *
     * @param  array<int, string>  $modules
     */
    public static function businessTypeFor(array $modules, ?string $shopType): ?string
    {
        if (in_array('shop', $modules, true)) {
            return $shopType;
        }

        return in_array('poultry', $modules, true) ? 'poultry' : 'fundraising';
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
                'color' => BrandColour::normalise($value === null ? null : (string) $value),
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

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
        'receipts' => ['receipt_header', 'receipt_footer', 'receipt_channels', 'payment_methods', 'receipt_support_line'],
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
    public const CORE_MODULES = ['shop', 'poultry', 'budget', 'sacco', 'rentals'];

    public const CORE_MODULE_MESSAGE = 'Pick at least one: Shop, Poultry farm or Budget & pledges.';

    /**
     * Modules an account keeps alone (docs/akiba AK-04, docs/estate-ledger KR-04): a SACCO's books are institutional and a
     * letting agency's rent books hold landlords' and tenants' money; each has its own plan line (PlanLine) and must
     * never describe the same money as Income & expenses. So a company with one has no other module, not even
     * `finance`, and no account moves into or out of one later: a treasurer who also runs a shop uses two accounts.
     * Refused with 422 `module_exclusive`.
     */
    public const EXCLUSIVE_MODULES = ['sacco', 'rentals'];

    // Names no other product (AK-68): a SACCO's staff only ever hear about their SACCO.
    public const EXCLUSIVE_MESSAGE = 'A SACCO keeps its books in an account of its own, and other books stay in theirs.';

    /** Each exclusive module's refusal, in its own app's words: none names another app (AK-68, KR-05). */
    public const EXCLUSIVE_MESSAGES = [
        'sacco' => self::EXCLUSIVE_MESSAGE,
        'rentals' => 'Estate Ledger keeps the rental books in an account of their own, and other books stay in theirs.',
    ];

    /** Each exclusive module's settings table: its books are set up once the account has a row there. */
    private const SETUP_TABLES = ['sacco' => 'sacco_settings', 'rentals' => 'rental_settings'];

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
            // The small "Powered by Budget Pro · WhatsApp …" line (SupportContact::receiptLineOn); null = the edition's default.
            'receipt_support_line' => ['sometimes', 'nullable', 'boolean'],
            'receipt_channels' => ['sometimes', 'array'],
            'receipt_channels.*' => [Rule::in(array_keys(self::RECEIPT_CHANNELS))],
            'payment_methods' => ['sometimes', 'array', 'min:1'],
            'payment_methods.*' => [Rule::in(array_keys(config('onboarding.payment_methods')))],
            'low_stock_default' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'negative_stock_policy' => ['sometimes', 'required', Rule::in(array_keys(self::NEGATIVE_STOCK))],
            'require_shift' => ['sometimes', 'boolean'],
            'credit_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'debt_reminders_enabled' => ['sometimes', 'boolean'],
            'enabled_modules' => ['sometimes', 'array', 'min:1', self::coreModuleRule($company)],
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

    /**
     * A list of modules must hold at least one core module (shop, poultry, budget, sacco or rentals), and an exclusive
     * one alone. With the company, its own app's words come first (exclusiveMessage).
     */
    public static function coreModuleRule(?Company $company = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($company) {
            if (! is_array($value) || $value === []) {
                return;
            }
            if (array_intersect($value, self::CORE_MODULES) === []) {
                $fail(self::CORE_MODULE_MESSAGE);
            } elseif (! self::exclusiveOk(array_map('strval', $value))) {
                $fail(self::exclusiveMessage(array_map('strval', $value), $company?->exists ? $company->modules() : []));
            }
        };
    }

    /**
     * The refusal for modules that break exclusivity, in the words of the app it concerns: the account's own exclusive
     * module first (a SACCO asking for rentals hears about its SACCO), else the one the list asks for. Never another
     * app's name to an app's own account (AK-68, KR-05).
     *
     * @param  array<int, string>  $modules  the modules asked for
     * @param  array<int, string>  $before  the account's modules now (empty for a new one)
     */
    public static function exclusiveMessage(array $modules, array $before = []): string
    {
        $module = self::exclusiveOf($before) ?? self::exclusiveOf($modules);

        return $module === null ? self::EXCLUSIVE_MESSAGE : (self::EXCLUSIVE_MESSAGES[$module] ?? self::EXCLUSIVE_MESSAGE);
    }

    /**
     * An exclusive module stands alone: a list holding one holds nothing else.
     *
     * @param  array<int, string>  $modules
     */
    public static function exclusiveOk(array $modules): bool
    {
        $modules = array_values(array_unique($modules));
        $exclusive = array_values(array_intersect($modules, self::EXCLUSIVE_MODULES));

        return $exclusive === [] || $modules === $exclusive && count($exclusive) === 1;
    }

    /**
     * The exclusive module a list of modules is built around, or null (a family account).
     *
     * @param  array<int, string>  $modules
     */
    public static function exclusiveOf(array $modules): ?string
    {
        return array_values(array_intersect(self::EXCLUSIVE_MODULES, $modules))[0] ?? null;
    }

    /**
     * The business types of the family's own setup screens (the classic wizard): every type but the ones built
     * around an exclusive module, which only their own app signs up (Akiba Ledger for `sacco`, Estate Ledger for `rentals`).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function familyBusinessTypes(): array
    {
        return array_filter((array) config('onboarding.business_types'), fn (array $t) => self::exclusiveOf((array) ($t['modules'] ?? [])) === null);
    }

    /**
     * Refuse a company's modules that break exclusivity, whoever writes them (the Company model calls this on
     * every save, so the API, the portal, the classic admin and the setup wizard all keep the rule):
     * an exclusive module beside another, or an existing account moving into or out of one.
     *
     * @param  array<int, string>  $before  the saved company's modules (empty for a new one)
     * @param  array<int, string>  $after
     */
    public static function assertModuleChange(array $before, array $after, bool $exists): void
    {
        if (! self::exclusiveOk($after) || ($exists && self::exclusiveOf($before) !== self::exclusiveOf($after))) {
            $before = $exists ? $before : [];
            // The module the refusal is about (the account's own first), so an app's account never hears of another (KR-05).
            $module = self::exclusiveOf($before) ?? self::exclusiveOf($after);
            throw \App\Exceptions\BusinessRuleException::make('module_exclusive', self::exclusiveMessage($after, $before), ['exclusive' => $module === null ? self::EXCLUSIVE_MODULES : [$module]]);
        }
    }

    /**
     * The rules for a list of modules sent under another name (PUT company/modules, sign-up's `modules`).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function modulesRules(string $key = 'modules', bool $required = true, ?Company $company = null): array
    {
        return [
            $key => $required ? ['required', 'array', 'min:1', self::coreModuleRule($company)] : ['nullable', 'array', self::coreModuleRule($company)],
            $key.'.*' => ['string', Rule::in(array_keys(config('onboarding.modules')))],
        ];
    }

    /**
     * Modules picked at sign-up, as stored: the known core modules in their usual order, then Income &
     * expenses, which every new account gets. An exclusive module (sacco, rentals) is kept alone, without Income &
     * expenses (AK-04, KR-04). Null when none was picked (the business type decides).
     *
     * @param  array<int, mixed>|null  $picked
     * @return list<string>|null
     */
    public static function signUpModules(?array $picked): ?array
    {
        $core = array_values(array_intersect(self::CORE_MODULES, array_map('strval', (array) $picked)));
        if (($exclusive = self::exclusiveOf($core)) !== null) {
            return [$exclusive];
        }

        return $core === [] ? null : [...$core, 'finance'];
    }

    /**
     * The business type that goes with modules picked at sign-up: the exclusive module's own (`sacco` for a SACCO
     * account, `rentals` for an Estate Ledger one), the shop's own type when Shop is picked, otherwise `poultry` (Poultry
     * farm picked) or `fundraising` (Budget & pledges only).
     *
     * @param  array<int, string>  $modules
     */
    public static function businessTypeFor(array $modules, ?string $shopType): ?string
    {
        if (($exclusive = self::exclusiveOf($modules)) !== null) {
            return $exclusive; // each exclusive module's business type has its name (config onboarding.business_types)
        }
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

    /**
     * Past figures are stored in the shop's currency, so it is fixed once there is a sale (or, for a SACCO or an Estate Ledger
     * account, once its books are set up: a row in its settings table).
     */
    public static function currencyLocked(Company $company): bool
    {
        if (! $company->exists || ! $company->currency) {
            return false;
        }
        if (self::hasSales((int) $company->id)) {
            return true;
        }
        $exclusive = self::exclusiveOf($company->modules());

        return $exclusive !== null && isset(self::SETUP_TABLES[$exclusive]) && DB::table(self::SETUP_TABLES[$exclusive])->where('company_id', $company->id)->exists();
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
                'receipt_support_line' => $value === null ? null : (bool) $value,
                'credit_terms_days' => (int) $value,
                'tax_rate', 'low_stock_default' => $value === null || $value === '' ? null : (float) $value,
                default => $value,
            };
        }

        return $company;
    }
}

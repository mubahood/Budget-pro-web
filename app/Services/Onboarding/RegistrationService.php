<?php

namespace App\Services\Onboarding;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialPeriod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The one way a tenant is created (P0-13). Web form, /api/v1, the legacy
 * mobile endpoint and Ping Pin all call this, so every company gets the same
 * things: owner user + owner role, company, membership row, trial
 * subscription, default financial period and account categories — in one
 * transaction.
 */
class RegistrationService
{
    public const PRODUCT_BUDGET = 'budget';

    public const PRODUCT_PINGPIN = 'pingpin';

    /**
     * The installer's channel (docs/STANDALONE_EDITION_PLAN.md §6): on a standalone copy it is the only way a
     * company is created, once. No trial: the company is put on the lifetime Standalone plan, and its
     * onboarding is left at the start so the owner meets the /welcome wizard.
     */
    public const CHANNEL_STANDALONE = 'standalone-install';

    public const BUSINESS_TYPES = ['retail', 'wholesale', 'pharmacy', 'agro_vet', 'hardware', 'restaurant', 'salon', 'boutique', 'electronics', 'other'];

    /**
     * Business types a sign-up may name: the original list (older apps still send `restaurant`)
     * plus every type the setup wizard offers (config('onboarding.business_types'): `restaurant_bar`,
     * `poultry`, `fundraising`…), so a type picked on any sign-up form is also a valid setup preset.
     *
     * @return list<string>
     */
    public static function businessTypes(): array
    {
        return array_values(array_unique(array_merge(self::BUSINESS_TYPES, array_keys((array) config('onboarding.business_types', [])))));
    }

    /** A short-lived proof that an OTP for this phone/email was verified (register flow). */
    public static function verificationToken(string $identifier): string
    {
        return \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['i' => $identifier, 'p' => 'register', 'exp' => now()->addMinutes(30)->timestamp]));
    }

    public static function verifiedIdentifier(?string $token): ?string
    {
        if (! $token) {
            return null;
        }
        try {
            $d = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($token), true);
        } catch (\Throwable $e) {
            return null;
        }

        return ($d['p'] ?? '') === 'register' && ($d['exp'] ?? 0) >= now()->timestamp ? (string) $d['i'] : null;
    }

    /**
     * Validation rules shared by every channel.
     *
     * @param  bool  $web  the HTML form also sends password_confirmation
     * @param  bool  $pingpin  Ping Pin signs up with a single name and email *or* phone
     */
    public static function rules(bool $web = false, bool $pingpin = false): array
    {
        $required = $pingpin ? 'nullable' : 'required';

        return [
            'first_name' => [$required, 'string', 'max:100'],
            'last_name' => [$required, 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:191'],
            'email' => [$pingpin ? 'nullable' : 'required_without:phone_number', 'nullable', 'email', 'max:191', 'unique:admin_users,email'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'password' => array_merge(['required', 'string', 'min:6', 'max:100'], $web ? ['confirmed'] : []),
            'company_name' => [$required, 'string', 'max:191'],
            'organisation_name' => ['nullable', 'string', 'max:191'],
            'company_phone' => ['nullable', 'string', 'max:30'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'currency' => [$required, 'string', Rule::in(config('saas.currencies'))],
            'country' => ['nullable', 'string', Rule::in(array_keys((array) config('onboarding.countries')))],
            'business_type' => ['nullable', 'string', Rule::in(self::businessTypes())],
            // Optional: what the account is for (shop, poultry, budget; at least one). Income & expenses is added.
            // Without it the business type decides, as before (Company::modules()).
            ...\App\Support\Rules\CompanyRules::modulesRules('modules', false),
            'timezone' => ['nullable', 'timezone'],
            'verification_token' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:191'],
        ];
    }

    public static function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
            'currency.in' => 'Please choose a supported currency.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }

    /**
     * @param  array  $data  validated input (see rules())
     * @return array{user: User, company: Company}
     *
     * @throws BusinessRuleException on business-level rejections (422)
     */
    public function register(array $data, string $product = self::PRODUCT_BUDGET, string $channel = 'api'): array
    {
        $standalone = $this->assertEditionAllows($product, $channel);
        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $name = trim($first.' '.$last) !== '' ? trim($first.' '.$last) : trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw BusinessRuleException::make('name_required', 'A name is required.');
        }
        $email = isset($data['email']) && $data['email'] !== '' ? strtolower(trim($data['email'])) : null;
        $phone = isset($data['phone_number']) && $data['phone_number'] !== '' ? trim($data['phone_number']) : null;
        if ($email === null && $phone === null) {
            throw BusinessRuleException::make('contact_required', 'Provide an email or phone number.');
        }
        if ($email !== null && User::withoutGlobalScopes()->where('email', $email)->exists()) {
            throw BusinessRuleException::make('email_taken', 'This email is already registered.');
        }
        $currencyGuess = strtoupper((string) ($data['currency'] ?? ''));
        $country = strtoupper((string) ($data['country'] ?? \App\Support\Phone::countryFor($currencyGuess)));
        $phoneE164 = $phone ? \App\Support\Phone::e164($phone, $country) : null;
        if ($phoneE164 !== null && User::withoutGlobalScopes()->where('phone_e164', $phoneE164)->exists()) {
            throw BusinessRuleException::make('phone_taken', 'This phone number is already registered.');
        }
        if ($email === null && $phoneE164 === null && User::withoutGlobalScopes()->where('phone_number', $phone)->exists()) {
            throw BusinessRuleException::make('phone_taken', 'This phone number is already registered.');
        }
        $verified = self::verifiedIdentifier($data['verification_token'] ?? null);
        if (strlen((string) ($data['password'] ?? '')) < 6) {
            throw BusinessRuleException::make('weak_password', 'Password must be at least 6 characters.');
        }
        $currency = strtoupper((string) ($data['currency'] ?? '')) ?: (string) config('saas.default_currency');
        $companyName = trim((string) ($data['company_name'] ?? $data['organisation_name'] ?? '')) ?: $name."'s Organisation";
        $trialDays = (int) config('saas.trial_days', 14);

        $result = DB::transaction(function () use ($data, $first, $last, $name, $email, $phone, $currency, $companyName, $trialDays, $product, $phoneE164, $verified, $country, $standalone) {
            $user = new User();
            $user->first_name = $first !== '' ? $first : $name;
            $user->last_name = $last;
            $user->name = $name;
            $user->username = $email ?? $phone;
            $user->email = $email;
            $user->phone_number = $phone;
            $user->phone_e164 = $phoneE164;
            if ($verified !== null && ($verified === $phoneE164 || $verified === $email)) {
                if ($verified === $phoneE164) {
                    $user->phone_verified_at = now();
                } else {
                    $user->email_verified_at = now();
                }
            }
            $user->password = Hash::make($data['password']);
            $user->status = 'Active';
            $user->save();

            $company = new Company();
            $company->owner_id = $user->id;
            $company->name = $companyName;
            $company->email = $email;
            $company->phone_number = $data['company_phone'] ?? $phone;
            $company->address = $data['company_address'] ?? null;
            $company->status = 'Active';
            $company->currency = $currency;
            $company->country = $country;
            $company->business_type = $data['business_type'] ?? null;
            // Modules picked at sign-up (a specialist app, or the web's choice): kept with Income & expenses,
            // and the type follows them (a farm or an event has no shop type).
            if ($modules = \App\Support\Rules\CompanyRules::signUpModules($data['modules'] ?? null)) {
                $company->enabled_modules = $modules;
                $company->business_type = \App\Support\Rules\CompanyRules::businessTypeFor($modules, $company->business_type);
            }
            $company->timezone = $data['timezone'] ?? null;
            $company->license_expire = $standalone ? null : now()->addDays($trialDays); // standalone: set by the lifetime plan below
            $company->save(); // created hook: owner->company_id, account categories

            \App\Models\CompanyMember::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            if ($standalone) {
                $plan = Plan::where('slug', \App\Services\Licensing\StandalonePlan::SLUG)->first();
                if ($plan === null) {
                    throw BusinessRuleException::make('standalone_plan_missing', 'The Standalone plan is missing: seed the reference data first (StandaloneReferenceSeeder).');
                }
                $company->activateSubscription($plan, 'standalone', null, true, 'lifetime');
            } elseif ($product === self::PRODUCT_PINGPIN) {
                $plan = \App\Models\PingPinPlan::where('slug', 'trial')->first();
                $days = $plan?->trial_days ?? $trialDays;
                \App\Models\PingPinSubscription::create([
                    'company_id' => $company->id, 'plan_id' => $plan?->id, 'status' => 'trialing', 'provider' => 'trial',
                    'starts_at' => now(), 'trial_ends_at' => now()->addDays($days), 'ends_at' => now()->addDays($days),
                ]);
            } else {
                $plan = Plan::where('slug', config('saas.default_plan', 'trial'))->first();
                Subscription::create([
                    'company_id' => $company->id, 'plan_id' => $plan?->id, 'status' => 'trialing', 'provider' => 'trial',
                    'starts_at' => now(), 'trial_ends_at' => now()->addDays($trialDays), 'ends_at' => now()->addDays($trialDays),
                ]);
            }

            $this->ensureDefaultPeriod((int) $company->id);

            $user->refresh();
            User::ensureCompanyOwnerRole($user);

            return ['user' => $user, 'company' => $company];
        });

        Log::info('Tenant registered', ['channel' => $channel, 'product' => $product, 'company_id' => $result['company']->id, 'user_id' => $result['user']->id]);
        OnboardingEvents::record((int) $result['company']->id, 'signed_up', ['product' => $product, 'via' => $channel],
            match ($channel) {
                'api' => 'app', 'shop-web' => 'web', self::CHANNEL_STANDALONE => 'installer', default => mb_substr($channel, 0, 12)
            }, (int) $result['user']->id);

        return $result;
    }

    /**
     * One business per standalone copy: only the installer registers, and only while there is no company yet
     * (Company::booted() and the triggers refuse a second one anyway). The installer's channel is never used on the SaaS.
     *
     * @return bool whether this is the standalone install
     */
    private function assertEditionAllows(string $product, string $channel): bool
    {
        if (! \App\Support\Edition::isStandalone()) {
            if ($channel === self::CHANNEL_STANDALONE) {
                throw BusinessRuleException::make('wrong_edition', 'The installer only runs on a standalone copy.');
            }

            return false;
        }
        if ($channel !== self::CHANNEL_STANDALONE || $product !== self::PRODUCT_BUDGET) {
            throw BusinessRuleException::make('registration_closed', 'New shops cannot sign up here: this installation is licensed for one business.');
        }
        if (Company::withoutGlobalScopes()->exists()) {
            throw BusinessRuleException::make('one_business', Company::ONE_BUSINESS);
        }

        return true;
    }

    /** Every company needs an active period before stock, sales or finance can be recorded. */
    public function ensureDefaultPeriod(int $companyId): FinancialPeriod
    {
        $existing = FinancialPeriod::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'Active')->first();
        if ($existing) {
            return $existing;
        }
        $period = new FinancialPeriod();
        $period->company_id = $companyId;
        $period->name = 'FY '.now()->year;
        $period->start_date = now()->startOfYear();
        $period->end_date = now()->endOfYear();
        $period->status = 'Active';
        $period->description = 'Default financial year created during registration';
        $period->total_investment = 0;
        $period->total_sales = 0;
        $period->total_profit = 0;
        $period->total_expenses = 0;
        $period->save();

        return $period;
    }
}
